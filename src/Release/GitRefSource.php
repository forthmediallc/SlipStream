<?php

namespace Slipstream\Release;

use RuntimeException;
use Slipstream\Slipstream;
use Symfony\Component\Process\Process;

/**
 * Resolve the deploy ref with `git ls-remote` against the mirror's origin:
 * the same remote, key and user every fetch already uses, and no clone.
 */
class GitRefSource implements ReleaseSource
{
    public function latest(DeployRef $ref): ReleaseRef
    {
        $mirror = Slipstream::appRoot().DIRECTORY_SEPARATOR.'repo.git';

        if (! is_dir($mirror)) {
            throw new RuntimeException("No bare mirror at {$mirror}. Run php artisan slipstream:bootstrap (or slipstream:provision).");
        }

        // A branch or one tag is asked for by name; a pattern needs the tag
        // list, filtered here rather than by git's own pattern rules.
        $args = $ref->kind === DeployRef::TAGS
            ? ['git', '--git-dir='.$mirror, 'ls-remote', '--tags', 'origin']
            : ['git', '--git-dir='.$mirror, 'ls-remote', 'origin', $ref->full(), $ref->full().'^{}'];

        $process = new Process($args, null, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: 'git ls-remote exited '.$process->getExitCode();

            throw new RuntimeException('Cannot read '.$ref->describe().' from origin: '.$error);
        }

        return self::pick($ref, $process->getOutput(), (bool) config('slipstream.trigger.prereleases', false));
    }

    /**
     * Choose the release from `ls-remote` output. An annotated tag's own
     * object id is not a commit; the peeled `^{}` line is, and wins.
     */
    public static function pick(DeployRef $ref, string $output, bool $prereleases = false): ReleaseRef
    {
        $shas = [];
        $peeled = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40}(?:[0-9a-f]{24})?)\s+(\S+)$/i', trim($line), $m) !== 1) {
                continue;
            }

            if (str_ends_with($m[2], '^{}')) {
                $peeled[substr($m[2], 0, -3)] = strtolower($m[1]);
            } else {
                $shas[$m[2]] = strtolower($m[1]);
            }
        }

        $commit = fn (string $full) => $peeled[$full] ?? $shas[$full] ?? null;

        if ($ref->kind !== DeployRef::TAGS) {
            $sha = $commit($ref->full());
            if ($sha === null) {
                throw new RuntimeException(ucfirst($ref->describe()).' does not exist on origin.');
            }

            return new ReleaseRef($sha, $ref->name, $ref->isTag());
        }

        $best = null;
        foreach (array_keys($shas + $peeled) as $full) {
            if (! str_starts_with($full, 'refs/tags/')) {
                continue;
            }

            $tag = substr($full, strlen('refs/tags/'));
            if (! fnmatch($ref->name, $tag) || ($version = self::version($tag)) === null) {
                continue;
            }

            if (! $prereleases && self::isPrerelease($version)) {
                continue;
            }

            if ($best === null || version_compare($version, $best['version'], '>')) {
                $best = ['tag' => $tag, 'version' => $version, 'sha' => $commit($full)];
            }
        }

        if ($best === null || $best['sha'] === null) {
            throw new RuntimeException('No tag on origin matches '.$ref->name.($prereleases ? '' : ' (pre-releases skipped)').'.');
        }

        return new ReleaseRef($best['sha'], $best['tag'], true);
    }

    /**
     * The version a tag names, or null for a tag that is not a version
     * (a pattern like v* also matches "vault-archive").
     */
    protected static function version(string $tag): ?string
    {
        $version = preg_replace('#^.*/#', '', $tag) ?? $tag;
        $version = ltrim($version, 'vV');

        return preg_match('/^\d+(\.\d+)*([.-].*)?$/', $version) === 1 ? $version : null;
    }

    protected static function isPrerelease(string $version): bool
    {
        return preg_match('/-|alpha|beta|rc|dev|pre/i', $version) === 1;
    }
}
