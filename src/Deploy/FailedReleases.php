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
     * @param  callable(string): void  $remove
     * @return list<string>  the paths removed
     */
    public static function prune(string $releasesDir, int $keep, callable $remove): array
    {
        $failed = array_values(array_filter(
            glob($releasesDir.DIRECTORY_SEPARATOR.'*'.self::SUFFIX, GLOB_ONLYDIR) ?: [],
            'is_dir'
        ));

        usort($failed, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        $removed = [];
        foreach (array_slice($failed, max(0, $keep)) as $old) {
            $remove($old);
            $removed[] = $old;
        }

        return $removed;
    }
}
