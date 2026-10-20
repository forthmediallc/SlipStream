<?php

namespace Slipstream\Release\Core;

/**
 * The manifest and statement for an archive, derived from the archive's own
 * bytes. The job that signs builds the manifest itself rather than trust the
 * one the build job wrote: a compromised build can only alter what the
 * tarball holds, never claim files it does not.
 */
final class ReleaseBuilder
{
    /**
     * @param  array{slipstream: string, environment: string, sha: string, ref: string, built_at: string, php: string, generated: list<string>}  $meta
     * @param  array{max_unpacked_bytes?: int, max_entries?: int}  $limits
     */
    public static function manifestFromArchive(string $tarGz, array $meta, array $limits = []): Manifest
    {
        $scratch = self::scratchDirectory();

        try {
            $files = TarReader::extract($tarGz, $scratch, $limits);
        } finally {
            self::removeTree($scratch);
        }

        ksort($files, SORT_STRING);
        $unpacked = 0;
        foreach ($files as [, $size]) {
            $unpacked += $size;
        }

        return new Manifest(
            $meta['slipstream'],
            $meta['environment'],
            $meta['sha'],
            $meta['ref'],
            $meta['built_at'],
            $meta['php'],
            basename($tarGz),
            hash_file('sha256', $tarGz),
            (int) filesize($tarGz),
            $unpacked,
            self::countEntries($files),
            $files,
            array_values($meta['generated']),
        );
    }

    /**
     * The statement that manifest.sig signs, for a manifest's exact bytes.
     */
    public static function statementFor(Manifest $manifest, string $manifestJson, string $environment): Statement
    {
        return new Statement(
            $manifest->sha,
            hash('sha256', $manifestJson),
            strlen($manifestJson),
            $manifest->archiveSha256,
            $manifest->ref,
            $environment,
            '',
        );
    }

    /**
     * Files plus the directory entries the writer emits for them.
     *
     * @param  array<string, array{0: string, 1: int}>  $files
     */
    private static function countEntries(array $files): int
    {
        $directories = [];
        foreach (array_keys($files) as $file) {
            $parts = explode('/', $file);
            array_pop($parts);
            for ($i = 1; $i <= count($parts); $i++) {
                $directories[implode('/', array_slice($parts, 0, $i))] = true;
            }
        }

        return count($files) + count($directories);
    }

    private static function scratchDirectory(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-release-'.bin2hex(random_bytes(6));
        if (! @mkdir($dir, 0700, true)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }

        return $dir;
    }

    private static function removeTree(string $path): void
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
                self::removeTree($path.DIRECTORY_SEPARATOR.$name);
            }
        }
        @rmdir($path);
    }
}
