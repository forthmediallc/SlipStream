<?php

namespace Slipstream\Release\Core;

/**
 * What a node generates into a release is executable and loaded on every
 * request, but no manifest can list it, because it holds the node's own
 * values: the caches in bootstrap/cache (config, routes, services,
 * packages, events) and, in artifact mode, the compiled views under
 * bootstrap/cache/views. The deploy records their hashes just before the
 * swap, and verification compares against that record, so a change after
 * the deploy shows up, and so does a file that appears.
 *
 * Format 1 (0.6.1 to 0.6.3) covered the top level of bootstrap/cache, where
 * views were not; format 2 covers all of it. A record is compared over the
 * scope it was written for: the first release a 0.6.3 deploy puts in place
 * compiles its views into the release, under a record that never saw them.
 */
final class Baseline
{
    public const FORMAT = 2;

    public const DIRECTORY = 'bootstrap/cache';

    /** Where views compile in artifact mode: in the release, beside the caches. */
    public const VIEWS = 'bootstrap/cache/views';

    /** The record's name inside the release's .slipstream directory. */
    public const FILE = 'generated.json';

    /**
     * Format 2: every .php file under bootstrap/cache, at any depth, and every
     * symlink there, whatever its name (never followed). Format 1: the .php
     * names at the top level only.
     *
     * @return array<string, array{0: string, 1: int}>  path => [sha256, size]; a symlink is ['symlink', 0]
     */
    public static function record(string $release, int $format = self::FORMAT): array
    {
        $files = [];
        self::walk(rtrim(str_replace('\\', '/', $release), '/'), self::DIRECTORY, $format >= 2, $files);
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $files
     */
    private static function walk(string $root, string $relative, bool $deep, array &$files): void
    {
        foreach (@scandir($root.'/'.$relative, SCANDIR_SORT_ASCENDING) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $relative.'/'.$name;
            $file = $root.'/'.$path;
            if (is_link($file)) {
                if ($deep || str_ends_with($name, '.php')) {
                    $files[$path] = ['symlink', 0];
                }
            } elseif (is_dir($file)) {
                if ($deep) {
                    self::walk($root, $path, $deep, $files);
                }
            } elseif (is_file($file) && str_ends_with($name, '.php')) {
                $files[$path] = [(string) hash_file('sha256', $file), (int) filesize($file)];
            }
        }
    }

    /**
     * The paths that changed, appeared or went missing since the record,
     * over the scope of the record's format.
     *
     * @param  array<string, array{0: string, 1: int}>  $baseline
     * @return list<string>
     */
    public static function compare(string $release, array $baseline, int $format = self::FORMAT): array
    {
        $now = self::record($release, $format);
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
     * @return array{format: int, files: array<string, array{0: string, 1: int}>}|null  null when the JSON is not a record
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        $format = is_array($data) ? ($data['format'] ?? null) : null;
        if (! in_array($format, [1, 2], true) || ! is_array($data['files'] ?? null)) {
            return null;
        }

        $files = [];
        foreach ($data['files'] as $path => $entry) {
            $path = (string) $path;
            if (! str_starts_with($path, self::DIRECTORY.'/') || ! is_array($entry) || count($entry) !== 2
                || ! is_string($entry[0] ?? null) || ! is_int($entry[1] ?? null)) {
                return null;
            }
            if ($format === 1 && substr_count($path, '/') !== 2) {
                return null;
            }
            $files[$path] = [$entry[0], $entry[1]];
        }

        return ['format' => $format, 'files' => $files];
    }

    /**
     * @return array<string, array{0: string, 1: int}>|null  null when the JSON is not a record
     */
    public static function fromJson(string $json): ?array
    {
        return self::parse($json)['files'] ?? null;
    }
}
