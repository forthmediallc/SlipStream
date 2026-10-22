<?php

namespace Slipstream\Release\Core;

/**
 * What a release must never carry, wherever it sits in the tree: VCS and
 * dependency directories, environment and credential files, local
 * databases, private keys, and what the node writes into a release itself.
 * The packager leaves the names out; publish applies the same rules to the
 * archive itself, because the build job that produced it is the untrusted
 * side and Object Lock makes whatever reaches the bucket permanent.
 *
 * Names match case-insensitively (.ENV is .env on half the filesystems a
 * checkout lands on). Key material is found by content, in the whole file;
 * under vendor/, where packages ship test keys, it is a warning.
 */
final class ReleaseRules
{
    /** Directory names never shipped, at any depth (lower case). */
    public const NEVER_SHIP_DIRECTORIES = ['.git', 'node_modules', '.aws', '.docker', '.ssh', '.kube'];

    /** File names never shipped, at any depth (lower case). */
    public const NEVER_SHIP_FILES = [
        'auth.json', '.envrc', '.npmrc', '.sentryclirc', '.netrc', '_netrc', '.pgpass',
        '.htpasswd', '.git-credentials', '.s3cfg', '.dockercfg',
    ];

    /** Key-store extensions. */
    public const KEY_STORES = ['p12', 'pfx', 'jks', 'keystore'];

    public const KEY_PATTERN = '/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY(?: BLOCK)?-----/';

    /**
     * Whether a path, or any directory on the way to it, must never ship.
     *
     * @param  list<string>  $allow  paths or path prefixes let through on purpose
     */
    public static function neverShips(string $path, array $allow = []): bool
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($allow !== [] && Manifest::under($path, $allow)) {
            return false;
        }

        $segments = explode('/', $path);
        $last = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            $lower = strtolower($segment);
            if (in_array($lower, self::NEVER_SHIP_DIRECTORIES, true)) {
                return true;
            }
            // .env, .env.production, .env~, .envrc — and a directory named so.
            if (str_starts_with($lower, '.env') && $lower !== '.env.example') {
                return true;
            }
            if ($i === $last && self::neverShipsName($lower, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the deploy writes into every release itself: the sidecar, and the
     * caches and compiled views under bootstrap/cache. Shipped, it would be
     * overwritten on the node and then reported as modified forever.
     */
    public static function nodeWritten(string $path): bool
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === 'RELEASE_SHA'
            || $path === '.slipstream' || str_starts_with($path, '.slipstream/')
            || (str_starts_with($path, 'bootstrap/cache/') && str_ends_with(strtolower($path), '.php'));
    }

    /**
     * Why contents must not ship: a private key, or a key store. Null when
     * they may. Public certificates (a CA bundle) are fine.
     *
     * @param  list<string>  $allow  paths or path prefixes allowed to carry key material
     */
    public static function secretProblem(string $path, ?string $contents, array $allow = []): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'vendor/') || Manifest::under($path, $allow)) {
            return null;
        }

        if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::KEY_STORES, true)) {
            return "{$path} is a key store";
        }
        if ($contents !== null && preg_match(self::KEY_PATTERN, $contents) === 1) {
            return "{$path} holds a private key";
        }

        return null;
    }

    /**
     * Whether a file holds a private key anywhere in it, read in chunks so
     * one appended to a large file is found too.
     */
    public static function containsPrivateKey(string $file): bool
    {
        $in = @fopen($file, 'rb');
        if ($in === false) {
            return false;
        }

        try {
            $carry = '';
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                if (preg_match(self::KEY_PATTERN, $carry.$chunk) === 1) {
                    return true;
                }
                // A header split across two chunks is caught next time round.
                $carry = substr($carry.$chunk, -128);
            }

            return false;
        } finally {
            fclose($in);
        }
    }

    /**
     * Every problem with the files of a tree, and what is only worth a
     * warning (key material under vendor/).
     *
     * @param  list<string>  $paths  release-relative files
     * @param  list<string>  $allow
     * @return array{problems: list<string>, warnings: list<string>}
     */
    public static function inspect(string $root, array $paths, array $allow = []): array
    {
        $problems = [];
        $warnings = [];

        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', (string) $path), '/');
            if (self::nodeWritten($path)) {
                $problems[] = "{$path} is written by the node itself";

                continue;
            }
            if (self::neverShips($path, $allow)) {
                $problems[] = "{$path} must never ship";

                continue;
            }
            if ($allow !== [] && Manifest::under($path, $allow)) {
                continue;
            }

            $problem = null;
            if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::KEY_STORES, true)) {
                $problem = "{$path} is a key store";
            } elseif (self::containsPrivateKey($root.'/'.$path)) {
                $problem = "{$path} holds a private key";
            }

            if ($problem !== null) {
                if (str_starts_with($path, 'vendor/')) {
                    $warnings[] = $problem;
                } else {
                    $problems[] = $problem;
                }
            }
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    private static function neverShipsName(string $lower, string $path): bool
    {
        if (in_array($lower, self::NEVER_SHIP_FILES, true)) {
            return true;
        }
        if (str_starts_with($lower, '.yarnrc')) {
            return true;
        }

        return ! str_starts_with($path, 'vendor/') && preg_match('/\.sqlite3?$/', $lower) === 1;
    }
}
