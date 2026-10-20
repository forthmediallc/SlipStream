<?php

namespace Slipstream\Release\Core;

/**
 * What a release contains: every file with its SHA-256 and size, the archive
 * that carries them, the PHP it was built with, and the paths a node writes
 * itself after unpacking (which a later verification must not count as
 * drift). Unsigned; bound to its statement by hash and size.
 */
final class Manifest
{
    public const FORMAT = 1;

    public const MAX_PATH = 4096;

    /**
     * @param  array<string, array{0: string, 1: int}>  $files  path => [sha256, size], sorted by path
     * @param  list<string>  $generated
     */
    public function __construct(
        public readonly string $slipstream,
        public readonly string $environment,
        public readonly string $sha,
        public readonly string $ref,
        public readonly string $builtAt,
        public readonly string $php,
        public readonly string $archiveName,
        public readonly string $archiveSha256,
        public readonly int $archiveSize,
        public readonly int $unpackedBytes,
        public readonly int $entries,
        public readonly array $files,
        public readonly array $generated,
    ) {}

    /**
     * @throws ArtifactRefused when the JSON is not a manifest
     */
    public static function fromJson(string $json): self
    {
        $refuse = fn (string $why) => throw new ArtifactRefused('manifest', 'the manifest is malformed: '.$why);

        $data = json_decode($json, true);
        if (! is_array($data)) {
            $refuse('not JSON');
        }
        if (($data['format'] ?? null) !== self::FORMAT) {
            $refuse('format '.json_encode($data['format'] ?? null).' is not '.self::FORMAT);
        }

        foreach (['slipstream', 'environment', 'sha', 'ref', 'built_at', 'php'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '' || strlen($data[$field]) > 255) {
                $refuse($field);
            }
        }
        if (preg_match('/^[0-9a-f]{40}$/', $data['sha']) !== 1) {
            $refuse('sha');
        }
        if (preg_match('/^\d+\.\d+\.\d+/', $data['php']) !== 1) {
            $refuse('php');
        }

        $archive = $data['archive'] ?? null;
        if (! is_array($archive)
            || ! is_string($archive['name'] ?? null) || ! self::validPath($archive['name']) || str_contains($archive['name'], '/')
            || ! is_string($archive['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $archive['sha256']) !== 1
            || ! is_int($archive['size'] ?? null) || $archive['size'] < 0
            || ! is_int($archive['unpacked_bytes'] ?? null) || $archive['unpacked_bytes'] < 0
            || ! is_int($archive['entries'] ?? null) || $archive['entries'] < 0) {
            $refuse('archive');
        }

        $files = $data['files'] ?? null;
        if (! is_array($files)) {
            $refuse('files');
        }
        $seen = [];
        $clean = [];
        foreach ($files as $path => $entry) {
            if (! is_string($path) || ! self::validPath($path)) {
                $refuse('file path '.json_encode($path, JSON_UNESCAPED_SLASHES));
            }
            if (! is_array($entry) || ! array_is_list($entry) || count($entry) !== 2
                || ! is_string($entry[0]) || preg_match('/^[0-9a-f]{64}$/', $entry[0]) !== 1
                || ! is_int($entry[1]) || $entry[1] < 0) {
                $refuse("file {$path}");
            }
            $folded = strtolower($path);
            if (isset($seen[$folded])) {
                $refuse("files {$path} and {$seen[$folded]} differ only in case");
            }
            $seen[$folded] = $path;
            $clean[$path] = [$entry[0], $entry[1]];
        }
        ksort($clean, SORT_STRING);

        $generated = $data['generated'] ?? [];
        if (! is_array($generated) || ! array_is_list($generated)) {
            $refuse('generated');
        }
        foreach ($generated as $path) {
            if (! is_string($path) || ! self::validPath($path)) {
                $refuse('generated path '.json_encode($path, JSON_UNESCAPED_SLASHES));
            }
        }

        return new self(
            $data['slipstream'], $data['environment'], $data['sha'], $data['ref'], $data['built_at'], $data['php'],
            $archive['name'], $archive['sha256'], $archive['size'], $archive['unpacked_bytes'], $archive['entries'],
            $clean, array_values($generated),
        );
    }

    /**
     * Deterministic: the same release always serialises to the same bytes.
     */
    public function toJson(): string
    {
        $files = $this->files;
        ksort($files, SORT_STRING);

        return json_encode([
            'format' => self::FORMAT,
            'slipstream' => $this->slipstream,
            'environment' => $this->environment,
            'sha' => $this->sha,
            'ref' => $this->ref,
            'built_at' => $this->builtAt,
            'php' => $this->php,
            'archive' => [
                'name' => $this->archiveName,
                'sha256' => $this->archiveSha256,
                'size' => $this->archiveSize,
                'unpacked_bytes' => $this->unpackedBytes,
                'entries' => $this->entries,
            ],
            'files' => $files === [] ? new \stdClass : $files,
            'generated' => $this->generated,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The PHP series the release was built with: "8.4".
     */
    public function phpSeries(): string
    {
        return implode('.', array_slice(explode('.', $this->php), 0, 2));
    }

    /**
     * A release-relative path: forward slashes, no empty, `.` or `..`
     * segments, no leading slash, no NUL or backslash.
     */
    public static function validPath(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_PATH || $path[0] === '/'
            || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare a tree on disk with the manifest. Paths under an ignored prefix
     * are skipped entirely; symlinks are never followed, and one anywhere
     * else is reported as such.
     *
     * @param  list<string>  $ignore  path prefixes
     * @return array{modified: list<string>, missing: list<string>, unlisted: list<string>, links: list<string>}
     */
    public function verifyTree(string $root, array $ignore = []): array
    {
        $ignored = function (string $path) use ($ignore): bool {
            foreach ($ignore as $prefix) {
                $prefix = rtrim($prefix, '/');
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    return true;
                }
            }

            return false;
        };

        $found = [];
        $links = [];
        $this->walk($root, '', $ignored, $found, $links);

        $modified = [];
        $missing = [];
        foreach ($this->files as $path => [$sha, $size]) {
            if ($ignored($path)) {
                continue;
            }
            if (! isset($found[$path])) {
                $missing[] = $path;

                continue;
            }
            if ($found[$path] !== $size || hash_file('sha256', $root.'/'.$path) !== $sha) {
                $modified[] = $path;
            }
        }

        $unlisted = array_values(array_filter(array_keys($found), fn (string $path) => ! isset($this->files[$path])));
        sort($unlisted, SORT_STRING);

        return ['modified' => $modified, 'missing' => $missing, 'unlisted' => $unlisted, 'links' => $links];
    }

    /**
     * @param  \Closure(string): bool  $ignored
     * @param  array<string, int>  $found  path => size
     * @param  list<string>  $links
     */
    private function walk(string $root, string $relative, \Closure $ignored, array &$found, array &$links): void
    {
        $dir = $relative === '' ? $root : $root.'/'.$relative;
        $names = @scandir($dir, SCANDIR_SORT_ASCENDING);
        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $relative === '' ? $name : $relative.'/'.$name;
            if ($ignored($path)) {
                continue;
            }
            $absolute = $root.'/'.$path;
            if (is_link($absolute)) {
                $links[] = $path;
            } elseif (is_dir($absolute)) {
                $this->walk($root, $path, $ignored, $found, $links);
            } elseif (is_file($absolute)) {
                $found[$path] = (int) filesize($absolute);
            }
        }
    }
}
