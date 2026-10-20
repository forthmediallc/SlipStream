<?php

namespace Slipstream\Release\Core;

/**
 * The caches a node generates into a release (bootstrap/cache/*.php: config,
 * routes, services, packages, events) are executable and loaded on every
 * request, but no manifest can list them: they hold the node's own values.
 * The deploy records their hashes just before the swap, and verification
 * compares against that record, so a change after the deploy shows up.
 */
final class Baseline
{
    public const FORMAT = 1;

    public const DIRECTORY = 'bootstrap/cache';

    /** The record's name inside the release's .slipstream directory. */
    public const FILE = 'generated.json';

    /**
     * @return array<string, array{0: string, 1: int}>  path => [sha256, size]; a symlink is ['symlink', 0]
     */
    public static function record(string $release): array
    {
        $files = [];
        $dir = rtrim($release, '/\\').'/'.self::DIRECTORY;

        foreach (@scandir($dir, SCANDIR_SORT_ASCENDING) ?: [] as $name) {
            if (! str_ends_with($name, '.php')) {
                continue;
            }
            $file = $dir.'/'.$name;
            if (is_link($file)) {
                $files[self::DIRECTORY.'/'.$name] = ['symlink', 0];
            } elseif (is_file($file)) {
                $files[self::DIRECTORY.'/'.$name] = [(string) hash_file('sha256', $file), (int) filesize($file)];
            }
        }

        return $files;
    }

    /**
     * The paths that changed, appeared or went missing since the record.
     *
     * @param  array<string, array{0: string, 1: int}>  $baseline
     * @return list<string>
     */
    public static function compare(string $release, array $baseline): array
    {
        $now = self::record($release);
        $changed = [];

        foreach ($baseline as $path => $entry) {
            if (($now[$path] ?? null) !== $entry) {
                $changed[] = (string) $path;
            }
        }
        foreach (array_keys($now) as $path) {
            if (! isset($baseline[$path])) {
                $changed[] = (string) $path;
            }
        }

        sort($changed, SORT_STRING);

        return array_values(array_unique($changed));
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $files
     */
    public static function toJson(array $files): string
    {
        ksort($files, SORT_STRING);

        return json_encode(['format' => self::FORMAT, 'files' => $files === [] ? new \stdClass : $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, array{0: string, 1: int}>|null  null when the JSON is not a baseline
     */
    public static function fromJson(string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['files'] ?? null)) {
            return null;
        }

        $files = [];
        foreach ($data['files'] as $path => $entry) {
            $path = (string) $path;
            if (! str_starts_with($path, self::DIRECTORY.'/') || ! is_array($entry) || count($entry) !== 2
                || ! is_string($entry[0] ?? null) || ! is_int($entry[1] ?? null)) {
                return null;
            }
            $files[$path] = [$entry[0], $entry[1]];
        }

        return $files;
    }
}
