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
     * What a node writes into a release itself; publish lists these unless
     * told otherwise. Not public/hot (only `npm run dev` writes it, and Vite
     * loads every script from the URL inside), and not public/storage (the
     * node makes it, but as a link, which STORAGE_LINKS checks instead).
     */
    public const DEFAULT_GENERATED = ['bootstrap/cache'];

    /**
     * The links `storage:link` makes, each to exactly its target (relative
     * to the app root, whose storage the release's storage links to).
     */
    public const STORAGE_LINKS = ['public/storage' => 'storage/app/public'];

    /**
     * Never excused, whatever a manifest's generated list says — releases
     * signed with 0.6.1's defaults list both: a planted public/hot sends
     * every page's scripts elsewhere, and a file under public/storage is
     * served (and, behind a careless nginx, run).
     */
    public const NEVER_GENERATED = ['public/hot', 'public/storage'];

    /** Files the deploy itself adds to every release. */
    public const NODE_WRITTEN = ['RELEASE_SHA', '.slipstream'];

    /** What the deploy replaces with links to the shared copies, besides the shared paths. */
    public const NODE_LINKED = ['storage', '.env', 'auth.json'];

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
            // json_decode turns a digit-only name ("404") into an int key.
            $path = (string) $path;
            if (! self::validPath($path)) {
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
     * Compare a tree on disk with the manifest. Symlinks are never followed.
     *
     * Every file the manifest lists is checked, wherever it is. Paths under
     * $generated only excuse regular files the manifest does not list — the
     * ones the node writes itself (bootstrap/cache) — so no ignore list can
     * hide a change to a file CI signed.
     *
     * A symlink is drift unless it is one the node makes: a path in
     * $linkTargets, whose link must resolve to exactly the target given there
     * (storage to the shared storage, public/storage to storage/app/public,
     * the .env to the shared .env), or one under $linked when no target is
     * known (null). A real directory under $linked — a release not linked
     * yet — is skipped; anywhere else what is in it is checked like any file.
     *
     * @param  list<string>  $linked  path prefixes the deploy replaces with links
     * @param  list<string>  $generated  path prefixes
     * @param  array<string, string|null>  $linkTargets  path => the absolute path a link there must resolve to
     * @return array{modified: list<string>, missing: list<string>, unlisted: list<string>, links: list<string>}
     */
    public function verifyTree(string $root, array $linked = [], array $generated = [], array $linkTargets = []): array
    {
        $isLinked = fn (string $path): bool => self::under($path, $linked);
        $isGenerated = fn (string $path): bool => self::under($path, $generated) && ! self::under($path, self::NEVER_GENERATED);

        $found = [];
        $links = [];
        $this->walk($root, '', $isLinked, $found, $links);

        $modified = [];
        $missing = [];
        foreach ($this->files as $path => [$sha, $size]) {
            $path = (string) $path;
            if ($isLinked($path)) {
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

        $unlisted = [];
        foreach (array_keys($found) as $path) {
            $path = (string) $path;
            if (! isset($this->files[$path]) && ! $isGenerated($path)) {
                $unlisted[] = $path;
            }
        }
        sort($unlisted, SORT_STRING);

        $targets = [];
        foreach ($linkTargets as $path => $target) {
            $targets[trim(str_replace('\\', '/', (string) $path), '/')] = $target;
        }
        $links = array_values(array_filter($links, function (string $path) use ($root, $targets, $isLinked): bool {
            if (array_key_exists($path, $targets)) {
                return $targets[$path] !== null && ! self::resolvesTo($root.'/'.$path, $targets[$path]);
            }

            return ! $isLinked($path);
        }));

        return ['modified' => $modified, 'missing' => $missing, 'unlisted' => $unlisted, 'links' => $links];
    }

    /**
     * Whether a link resolves to exactly the given path.
     */
    public static function resolvesTo(string $link, string $target): bool
    {
        $actual = realpath($link);
        if ($actual === false) {
            return false;
        }
        $expected = realpath($target) ?: $target;
        $normalize = fn (string $path) => rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($normalize($actual), $normalize($expected)) === 0
            : $normalize($actual) === $normalize($expected);
    }

    /**
     * Where each link the deploy makes must lead, for an app root: storage to
     * the shared storage, public/storage (and any other storage link) into
     * it exactly, the .env and auth.json to the shared copies, each shared
     * path to its shared directory.
     *
     * @param  array<string, string>  $storageLinks  release path => target relative to the app root
     * @param  array<string, string|null>  $sharedPaths  release path => shared path relative to the app root (null: not known)
     * @return array<string, string|null>
     */
    public static function linkTargets(string $appRoot, array $storageLinks = ['public/storage' => 'storage/app/public'], array $sharedPaths = []): array
    {
        $appRoot = rtrim(str_replace('\\', '/', $appRoot), '/');
        $targets = [
            'storage' => $appRoot.'/storage',
            '.env' => $appRoot.'/.env',
            'auth.json' => $appRoot.'/auth.json',
        ];
        foreach ($storageLinks as $path => $target) {
            $targets[trim((string) $path, '/')] = $appRoot.'/'.trim($target, '/');
        }
        foreach ($sharedPaths as $path => $shared) {
            $targets[trim((string) $path, '/')] = $shared === null ? null : $appRoot.'/'.trim(str_replace('\\', '/', $shared), '/');
        }

        return $targets;
    }

    /**
     * @param  list<string>  $prefixes
     */
    public static function under(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            $prefix = trim(str_replace('\\', '/', (string) $prefix), '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return true;
            }
        }

        return false;
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
            $absolute = $root.'/'.$path;
            // A link is always looked at, where the node makes one too: the
            // target is what matters.
            if (is_link($absolute)) {
                $links[] = $path;

                continue;
            }
            if ($ignored($path)) {
                continue;
            }
            if (is_dir($absolute)) {
                $this->walk($root, $path, $ignored, $found, $links);
            } elseif (is_file($absolute)) {
                $found[$path] = (int) filesize($absolute);
            }
        }
    }
}
