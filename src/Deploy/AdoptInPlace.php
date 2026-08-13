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
     * releases, repo.git, deploy and current belong to the layout, not to a
     * release. node_modules is neither served nor present in a git-built
     * release, and is usually the largest thing in the tree.
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

        $this->copyExcluding($this->appRoot, $release, self::EXCLUDED);
        $this->linkShared($release);
        $this->linkSharedPaths($release);

        if ($sha) {
            file_put_contents($release.DIRECTORY_SEPARATOR.'RELEASE_SHA', $sha."\n");
        }

        $this->linkCurrent($release);

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
     * @param  list<string>  $excludedNames  top-level entries to skip
     */
    protected function copyExcluding(string $from, string $to, array $excludedNames = []): void
    {
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $excludedNames, true)) {
                continue;
            }

            $src = $from.DIRECTORY_SEPARATOR.$name;
            $dst = $to.DIRECTORY_SEPARATOR.$name;

            if (is_link($src)) {
                $target = readlink($src);
                if ($target !== false) {
                    @symlink($target, $dst);
                }

                continue;
            }

            if (is_dir($src)) {
                $mode = (@fileperms($src) & 0777) ?: 0755;
                if (! is_dir($dst)) {
                    mkdir($dst, $mode, true);
                    @chmod($dst, $mode);
                }
                $this->copyExcluding($src, $dst);

                continue;
            }

            if (copy($src, $dst)) {
                $mode = @fileperms($src) & 0777;
                if ($mode) {
                    @chmod($dst, $mode);
                }
            }
        }
    }

    /**
     * Seed and link the configured shared paths (uploads and the like).
     *
     * These must be links in the adopted release, not the real directories the
     * copy produced. Once the document root is flipped, writes go through the
     * release — and a later deploy builds its own release from git, overlays
     * shared/ from *that* content, and links it. Anything written into the
     * adopted release's real directory would simply stop being served, and
     * would go for good when the release ages out of keep-N.
     *
     * Seeding shared/ from the live data also fixes the plain first-deploy
     * case, where shared/ is created empty and the git-built release has
     * nothing to overlay because the directory is gitignored.
     */
    protected function linkSharedPaths(string $release): void
    {
        foreach ($this->sharedPaths as $releaseRelative => $sharedRelative) {
            $sharedAbs = $this->appRoot.DIRECTORY_SEPARATOR.$this->normalise($sharedRelative);
            $releaseAbs = $release.DIRECTORY_SEPARATOR.$this->normalise($releaseRelative);

            $parent = dirname($sharedAbs);
            if (! is_dir($parent)) {
                mkdir($parent, 0755, true);
            }

            if (! is_dir($sharedAbs)) {
                // The copy already put the live data in the release; move it
                // into shared rather than copying the same bytes twice.
                if (is_dir($releaseAbs) && ! is_link($releaseAbs)) {
                    rename($releaseAbs, $sharedAbs);
                } else {
                    mkdir($sharedAbs, 0755, true);
                }
            } elseif (is_dir($releaseAbs) && ! is_link($releaseAbs)) {
                // Shared already exists (a re-run, or a hand-made layout):
                // fill in anything it does not have, then drop the copy.
                $this->copyMissing($releaseAbs, $sharedAbs);
            }

            $releaseParent = dirname($releaseAbs);
            if (! is_dir($releaseParent)) {
                mkdir($releaseParent, 0755, true);
            }

            $this->remove($releaseAbs);

            if (! @symlink($sharedAbs, $releaseAbs)) {
                throw new RuntimeException("Failed to link {$releaseAbs} -> {$sharedAbs}");
            }
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
     * Same race-safe pattern as the deploy swap: create a temp link and rename
     * it over, so `current` is never briefly missing.
     */
    protected function linkCurrent(string $release): void
    {
        $current = $this->currentPath();

        if (@symlink($release, $current)) {
            return;
        }

        $tmp = $current.'.tmp.'.bin2hex(random_bytes(4));
        if (! @symlink($release, $tmp)) {
            throw new RuntimeException("Failed to link {$current} -> {$release}");
        }

        if (! @rename($tmp, $current)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to move {$tmp} into place as {$current}");
        }
    }
}
