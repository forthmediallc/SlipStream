<?php

namespace Slipstream\Deploy;

/**
 * Retention for releases kept behind after a failed deploy.
 *
 * A failed release is renamed rather than deleted so the failing command can
 * still be re-run in the directory it failed in — the failure log names the
 * path, and without this that path is gone by the time anyone reads it.
 */
class FailedReleases
{
    public const SUFFIX = '.failed';

    public static function isFailed(string $path): bool
    {
        return str_ends_with($path, self::SUFFIX);
    }

    /**
     * Split release directories into live candidates and preserved failures.
     *
     * @param  list<string>  $dirs
     * @return array{0: list<string>, 1: list<string>}  [releases, failed]
     */
    public static function partition(array $dirs): array
    {
        $releases = [];
        $failed = [];

        foreach ($dirs as $dir) {
            self::isFailed($dir) ? $failed[] = $dir : $releases[] = $dir;
        }

        return [$releases, $failed];
    }

    /**
     * Keep the newest $keep failed releases, delete the rest.
     *
     * @param  callable(string): (bool|void)  $remove  return false to veto one path
     * @param  list<string>|null  $failed  already-listed .failed dirs, or null to glob
     * @return list<string>  the paths removed
     */
    public static function prune(string $releasesDir, int $keep, callable $remove, ?array $failed = null): array
    {
        $failed ??= glob($releasesDir.DIRECTORY_SEPARATOR.'*'.self::SUFFIX, GLOB_ONLYDIR) ?: [];

        usort($failed, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        $removed = [];
        foreach (array_slice($failed, max(0, $keep)) as $old) {
            if ($remove($old) !== false) {
                $removed[] = $old;
            }
        }

        return $removed;
    }
}
