<?php

namespace Slipstream\Deploy;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Converts a plain git checkout into the release layout without building.
 *
 * The documented setup — "point your document root at {app_root}/current/public"
 * — has nothing to point at on a fresh install: `current` only comes into being
 * at the swap step of the first successful deploy. That leaves an operator
 * choosing between repointing the web server at a path that does not exist yet
 * (site down until a deploy succeeds) and deploying first with no signal for
 * when it is safe to flip.
 *
 * Adopting copies the live tree into releases/<ts>_<sha> as-is and links
 * `current` at it. No composer, no hooks, no health gate: the release is
 * byte-identical to what is serving right now, so there is nothing to build and
 * nothing to fail, and the document-root flip becomes a config edit that can be
 * done at leisure.
 */
class AdoptInPlace
{
    /**
     * Top-level entries not copied into the adopted release.
     *
     * storage/.env/auth.json are shared and get symlinked instead; .git,
     * releases, repo.git, deploy, shared and current belong to the layout, not
     * to a release (a git-built release never contains them, and shared/ can
     * hold every upload the site has ever taken). node_modules is neither
     * served nor present in a git-built release, and is usually the largest
     * thing in the tree.
     */
    public const EXCLUDED = [
        'storage',
        '.env',
        'auth.json',
        '.git',
        'releases',
        'repo.git',
        'deploy',
        'current',
        'shared',
        'node_modules',
    ];

    /**
     * @param  array<string, string>  $sharedPaths  release-relative => layout-relative,
     *                                              as configured in slipstream.deploy.shared_paths
     */
    public function __construct(
        protected string $appRoot,
        protected array $sharedPaths = []
    ) {}

    /**
     * Every path the copy must not descend into, as layout-relative paths:
     * the configured shared destinations (an out-of-`shared/` destination
     * would otherwise pull every upload into the release) and the live
     * source directories themselves (linkSharedPaths() moves those into the
     * shared location instead of copying them).
     *
     * Matched as whole relative paths rather than by top-level name: a shared
     * path is free to live inside a directory the release needs (say
     * `public/uploads`), and excluding `public` wholesale would empty the
     * release.
     *
     * @return list<string>
     */
    protected function sharedPathExclusions(): array
    {
        $paths = [];

        foreach ($this->sharedPaths as $releaseRelative => $sharedRelative) {
            foreach ([$releaseRelative, $sharedRelative] as $relative) {
                $normalised = $this->normalise((string) $relative);
                if ($normalised !== '') {
                    $paths[] = $normalised;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * A checkout that is serving directly, with no release layout around it.
     */
    public function isPlainCheckout(): bool
    {
        if (! is_file($this->appRoot.DIRECTORY_SEPARATOR.'artisan')) {
            return false;
        }

        if ($this->currentExists()) {
            return false;
        }

        // provision creates an empty releases/ moments before this runs, so
        // only a populated one means the layout is already in use. Releases
        // kept from a failed deploy do not count: they are exactly the state a
        // first-timer is in when the first deploy fails and they come back to
        // adopt, and nothing was ever served from one.
        return $this->existingReleases() === [];
    }

    /**
     * Why isPlainCheckout() said no, in a sentence an operator can act on.
     */
    public function whyNotAdoptable(): string
    {
        if (! is_file($this->appRoot.DIRECTORY_SEPARATOR.'artisan')) {
            return '  No artisan file at '.$this->appRoot.' — this does not look like a Laravel checkout.';
        }

        if ($this->currentExists()) {
            return '  current already exists at '.$this->currentPath().' — the layout is already in use.';
        }

        $releases = $this->existingReleases();
        if ($releases !== []) {
            return '  releases/ already holds '.count($releases).' release(s), starting with '
                .basename($releases[0]).' — the layout is already in use.';
        }

        return '  This directory is already using the release layout.';
    }

    public function currentExists(): bool
    {
        $current = $this->currentPath();

        return is_link($current) || is_dir($current) || is_file($current);
    }

    /**
     * A real `current` directory (rather than a symlink) is a dead end: the
     * deploy swap is `mv -T`, which fails against a non-empty directory.
     */
    public function currentIsRealDirectory(): bool
    {
        $current = $this->currentPath();

        return is_dir($current) && ! is_link($current);
    }

    /**
     * Copy the live tree into a release and point `current` at it.
     *
     * @return string  the adopted release path
     */
    public function adopt(): string
    {
        if ($this->currentIsRealDirectory()) {
            throw new RuntimeException(
                'current exists as a real directory; the deploy swap (mv -T) cannot replace it. '
                .'Remove or rename it first.'
            );
        }

        // A live site always has one; every release links its .env here, so
        // adopting without it produces a release that cannot boot behind a
        // provision run that just declared the layout ready to serve.
        if (! is_file($this->appRoot.DIRECTORY_SEPARATOR.'.env')) {
            throw new RuntimeException(
                'No .env at '.$this->appRoot.' — create the shared .env at the layout root before adopting.'
            );
        }

        $releasesDir = $this->appRoot.DIRECTORY_SEPARATOR.'releases';
        if (! is_dir($releasesDir) && ! mkdir($releasesDir, 0755, true) && ! is_dir($releasesDir)) {
            throw new RuntimeException("Could not create {$releasesDir}");
        }

        $sha = $this->headSha();
        $release = $releasesDir.DIRECTORY_SEPARATOR.gmdate('Ymd_His').'_'.($sha ? substr($sha, 0, 7) : 'adopted');

        if (! mkdir($release, 0755, true) && ! is_dir($release)) {
            throw new RuntimeException("Could not create {$release}");
        }
        @chmod($release, 0755);

        // Anything that fails part-way leaves a half-copied tree in releases/.
        // Left there it looks exactly like a real release: it would block the
        // retry (isPlainCheckout reads a populated releases/ as "layout already
        // in use") and burn a keep-N slot. remove() unlinks symlinks rather
        // than following them, so tearing it down cannot reach shared storage.
        try {
            $this->copyExcluding($this->appRoot, $release, self::EXCLUDED, $this->sharedPathExclusions());
            $this->linkShared($release);
            $this->linkSharedPaths($release);

            if ($sha) {
                file_put_contents($release.DIRECTORY_SEPARATOR.'RELEASE_SHA', $sha."\n");
            }

            $this->linkCurrent($release);
        } catch (\Throwable $e) {
            $this->remove($release);

            throw $e;
        }

        return $release;
    }

    public function currentPath(): string
    {
        return $this->appRoot.DIRECTORY_SEPARATOR.'current';
    }

    /**
     * Deployable releases, ignoring any kept from a failed deploy.
     *
     * @return list<string>
     */
    public function existingReleases(): array
    {
        $all = glob($this->appRoot.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];

        [$releases] = FailedReleases::partition($all);

        return $releases;
    }

    /**
     * HEAD of the checkout, or null when it is not a git repo. Only used to
     * name the release directory, which nothing parses.
     */
    public function headSha(): ?string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], $this->appRoot);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $sha = trim($process->getOutput());

        return preg_match('/^[0-9a-f]{40}$/i', $sha) === 1 ? $sha : null;
    }

    /**
     * Recursive copy that preserves symlinks as symlinks.
     *
     * Deployer::copyTree() is the wrong tool here: it materialises links as
     * real directories and skips existing files (right for overlaying shared
     * storage, wrong for reproducing a tree exactly). public/storage is the
     * common case — as a copied directory it would silently stop tracking
     * shared storage.
     *
     * Every failure throws. The whole promise of adopting is that the release
     * is byte-identical to what is serving, so a half-copied tree must abort
     * the adopt rather than become the thing `current` points at: an
     * unreadable directory would otherwise arrive as a silently EMPTY one and
     * only surface as 404s and 500s once the document root is flipped.
     *
     * @param  list<string>  $excludedNames  top-level entries to skip
     * @param  list<string>  $excludedPaths  layout-relative paths to skip at any depth
     * @param  string  $relative  the path of $from relative to the layout root
     */
    protected function copyExcluding(
        string $from,
        string $to,
        array $excludedNames = [],
        array $excludedPaths = [],
        string $relative = ''
    ): void {
        $entries = @scandir($from);

        if ($entries === false) {
            throw new RuntimeException(
                "Cannot read {$from} — the adopted release would be missing everything under it. "
                .'Run provision as a user that can read the whole checkout.'
            );
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $excludedNames, true)) {
                continue;
            }

            $entryRelative = $relative === '' ? $name : $relative.DIRECTORY_SEPARATOR.$name;
            if (in_array($entryRelative, $excludedPaths, true)) {
                continue;
            }

            $src = $from.DIRECTORY_SEPARATOR.$name;
            $dst = $to.DIRECTORY_SEPARATOR.$name;

            if (is_link($src)) {
                $target = readlink($src);
                if ($target === false) {
                    throw new RuntimeException("Could not read the symlink {$src}");
                }
                if (! @symlink($target, $dst)) {
                    throw new RuntimeException("Failed to recreate the symlink {$dst} -> {$target}");
                }

                continue;
            }

            if (is_dir($src)) {
                $mode = (@fileperms($src) & 0777) ?: 0755;
                if (! is_dir($dst) && ! @mkdir($dst, $mode, true) && ! is_dir($dst)) {
                    throw new RuntimeException("Could not create {$dst}");
                }
                @chmod($dst, $mode);
                $this->copyExcluding($src, $dst, [], $excludedPaths, $entryRelative);

                continue;
            }

            if (! @copy($src, $dst)) {
                throw new RuntimeException("Failed to copy {$src} to {$dst}");
            }

            $mode = @fileperms($src) & 0777;
            if ($mode) {
                @chmod($dst, $mode);
            }
        }
    }

    /**
     * Move each configured shared path into its shared location and link it
     * from BOTH roots — the release and the still-live checkout.
     *
     * The release side must be a link because a later deploy overlays shared/
     * from git content and links it: data sitting in a release's real
     * directory stops being served at the next deploy and is deleted when the
     * release ages out of keep-N.
     *
     * The live side must become a link for the same money: until the operator
     * flips the document root — which provision explicitly invites them to
     * postpone — the OLD root keeps serving, and every upload it takes would
     * land in a directory nothing links to. Converging both roots on the
     * shared directory is what makes "both paths serve identical bytes"
     * actually true for the paths where it matters most.
     *
     * Seeding shared/ from the live data is also the only way a shared path
     * ever gets seeded from an existing site — a plain first deploy creates it
     * empty, because the directory is usually gitignored.
     */
    protected function linkSharedPaths(string $release): void
    {
        foreach ($this->sharedPaths as $releaseRelative => $sharedRelative) {
            $sharedAbs = $this->appRoot.DIRECTORY_SEPARATOR.$this->normalise($sharedRelative);
            $liveAbs = $this->appRoot.DIRECTORY_SEPARATOR.$this->normalise($releaseRelative);
            $releaseAbs = $release.DIRECTORY_SEPARATOR.$this->normalise($releaseRelative);

            $parent = dirname($sharedAbs);
            if (! is_dir($parent) && ! @mkdir($parent, 0755, true) && ! is_dir($parent)) {
                throw new RuntimeException("Could not create the shared parent directory {$parent}");
            }

            $this->shareLiveDirectory($liveAbs, $sharedAbs);

            $releaseParent = dirname($releaseAbs);
            if (! is_dir($releaseParent) && ! @mkdir($releaseParent, 0755, true) && ! is_dir($releaseParent)) {
                throw new RuntimeException("Could not create {$releaseParent}");
            }

            $this->remove($releaseAbs);

            if (! @symlink($sharedAbs, $releaseAbs)) {
                throw new RuntimeException("Failed to link {$releaseAbs} -> {$sharedAbs}");
            }
        }
    }

    /**
     * Make $liveAbs a symlink to $sharedAbs, seeding $sharedAbs from its
     * content, without ever leaving the live path missing for longer than the
     * moment between two renames — the old document root is serving traffic
     * through it the whole time.
     *
     * Every step is checked, and a failure puts the live directory back before
     * throwing: the worst outcome of a failed adopt must be "nothing changed",
     * never "the site lost its uploads directory".
     */
    protected function shareLiveDirectory(string $liveAbs, string $sharedAbs): void
    {
        // The live path IS the shared location (release-relative and
        // layout-relative sides of the config name the same directory):
        // nothing to move and nothing to link on the live side — the release
        // link pointing here is the whole arrangement.
        if ($liveAbs === $sharedAbs) {
            if (! is_dir($sharedAbs) && ! @mkdir($sharedAbs, 0755, true) && ! is_dir($sharedAbs)) {
                throw new RuntimeException("Could not create the shared directory {$sharedAbs}");
            }

            return;
        }

        // Already a link (a previous adopt, or a hand-converted layout):
        // just make sure it points at the shared location.
        if (is_link($liveAbs)) {
            if (realpath($liveAbs) !== realpath($sharedAbs) || ! is_dir($sharedAbs)) {
                if (! is_dir($sharedAbs) && ! @mkdir($sharedAbs, 0755, true) && ! is_dir($sharedAbs)) {
                    throw new RuntimeException("Could not create the shared directory {$sharedAbs}");
                }
                @unlink($liveAbs);
                if (! @symlink($sharedAbs, $liveAbs)) {
                    throw new RuntimeException("Failed to link {$liveAbs} -> {$sharedAbs}");
                }
            }

            return;
        }

        if (! is_dir($liveAbs)) {
            // Nothing live to seed from — just make sure shared exists so the
            // links have something to point at.
            if (! is_dir($sharedAbs) && ! @mkdir($sharedAbs, 0755, true) && ! is_dir($sharedAbs)) {
                throw new RuntimeException("Could not create the shared directory {$sharedAbs}");
            }

            return;
        }

        if (! is_dir($sharedAbs)) {
            // Seed shared by moving the live directory into place, then link
            // the old path at it. The gap where the live path is missing is
            // the symlink+rename below — two syscalls.
            if (! @rename($liveAbs, $sharedAbs)) {
                throw new RuntimeException("Failed to move the live data at {$liveAbs} into {$sharedAbs}");
            }

            try {
                $this->placeLink($sharedAbs, $liveAbs);
            } catch (\Throwable $e) {
                // Put the site back exactly as it was.
                @rename($sharedAbs, $liveAbs);

                throw $e;
            }

            return;
        }

        // Shared already exists (a re-run, or a hand-made layout): existing
        // shared files win, live fills the gaps — the same rule the deployer
        // uses when overlaying shared data.
        $this->copyMissing($liveAbs, $sharedAbs);

        $displaced = $liveAbs.'.pre-adopt.'.bin2hex(random_bytes(4));
        if (! @rename($liveAbs, $displaced)) {
            throw new RuntimeException("Could not move {$liveAbs} aside to replace it with a link");
        }

        try {
            $this->placeLink($sharedAbs, $liveAbs);
        } catch (\Throwable $e) {
            @rename($displaced, $liveAbs);

            throw $e;
        }

        $this->remove($displaced);
    }

    /**
     * Create $link pointing at $target via a temp name and an atomic rename.
     */
    protected function placeLink(string $target, string $link): void
    {
        $tmp = $link.'.tmp.'.bin2hex(random_bytes(4));

        if (! @symlink($target, $tmp)) {
            throw new RuntimeException("Failed to link {$link} -> {$target}");
        }

        if (! @rename($tmp, $link)) {
            @unlink($tmp);

            throw new RuntimeException("Failed to move {$tmp} into place as {$link}");
        }
    }

    protected function normalise(string $relative): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($relative, '/\\'));
    }

    /**
     * Copy anything the destination does not already have (existing files win,
     * the same rule Deployer::copyTree uses for shared data).
     */
    protected function copyMissing(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $src = $from.DIRECTORY_SEPARATOR.$name;
            $dst = $to.DIRECTORY_SEPARATOR.$name;

            if (is_dir($src) && ! is_link($src)) {
                if (! is_dir($dst)) {
                    mkdir($dst, 0755, true);
                }
                $this->copyMissing($src, $dst);
            } elseif (! file_exists($dst)) {
                copy($src, $dst);
            }
        }
    }

    /**
     * Delete a file, link, or directory tree. Links are unlinked, never
     * followed — removing a link into shared storage must not touch what it
     * points at.
     */
    protected function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path.DIRECTORY_SEPARATOR.$name);
            }
        }

        @rmdir($path);
    }

    /**
     * Give the adopted release the same shared links a deployed release gets.
     *
     * Storage is already live at the layout root, so there is no skeleton to
     * seed and no ownership to fix.
     */
    protected function linkShared(string $release): void
    {
        $links = [
            '.env' => $this->appRoot.DIRECTORY_SEPARATOR.'.env',
            'storage' => $this->appRoot.DIRECTORY_SEPARATOR.'storage',
            'auth.json' => $this->appRoot.DIRECTORY_SEPARATOR.'auth.json',
        ];

        foreach ($links as $name => $target) {
            if (! file_exists($target)) {
                continue;
            }

            $link = $release.DIRECTORY_SEPARATOR.$name;
            if (file_exists($link) || is_link($link)) {
                @unlink($link);
            }

            if (! @symlink($target, $link)) {
                throw new RuntimeException("Failed to link {$link} -> {$target}");
            }
        }
    }

    /**
     * Temp link + atomic rename, so `current` is never half-made: one path for
     * both the fresh and the already-exists case, and genuinely the same
     * race-safe shape the deploy swap uses.
     */
    protected function linkCurrent(string $release): void
    {
        $this->placeLink($release, $this->currentPath());
    }
}
