<?php

namespace Slipstream\Release\Core;

/**
 * What a release must never carry, wherever it sits in the tree: VCS and
 * dependency directories, environment and credential files, local
 * databases, private keys. The packager leaves the names out; publish
 * applies the same rules to the archive itself, because the build job that
 * produced it is the untrusted side and Object Lock makes whatever reaches
 * the bucket permanent.
 */
final class ReleaseRules
{
    /** Directory names never shipped, at any depth. */
    public const NEVER_SHIP_DIRECTORIES = ['.git', 'node_modules'];

    /** File names never shipped, at any depth. */
    public const NEVER_SHIP_FILES = ['auth.json', '.envrc', '.npmrc', '.sentryclirc'];

    /** Key-store extensions refused outside vendor/. */
    public const KEY_STORES = ['p12', 'pfx', 'jks', 'keystore'];

    /** Files larger than this are not read for key material. */
    public const SCAN_LIMIT = 1048576;

    /**
     * Whether a path, or any directory on the way to it, must never ship.
     */
    public static function neverShips(string $path): bool
    {
        $segments = explode('/', trim(str_replace('\\', '/', $path), '/'));
        $last = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            if (in_array($segment, self::NEVER_SHIP_DIRECTORIES, true)) {
                return true;
            }
            if ($i === $last && self::neverShipsName($segment, $path)) {
                return true;
            }
            // Name rules apply to directories on the way too (.env.d, …).
            if ($i < $last && (str_starts_with($segment, '.env') && $segment !== '.env.example')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a file must not ship, judged by what it holds: a private key, or
     * a key store. Null when it may. Vendor code and allowed paths pass;
     * public certificates (a CA bundle) are fine.
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
        if ($contents !== null && preg_match('/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY(?: BLOCK)?-----/', $contents) === 1) {
            return "{$path} holds a private key";
        }

        return null;
    }

    /**
     * Every problem with a tree on disk, for the files given: names that
     * never ship and key material.
     *
     * @param  list<string>  $paths  release-relative files
     * @param  list<string>  $allow
     * @return list<string>
     */
    public static function problems(string $root, array $paths, array $allow = []): array
    {
        $problems = [];

        foreach ($paths as $path) {
            $path = (string) $path;
            if (self::neverShips($path)) {
                $problems[] = "{$path} must never ship";

                continue;
            }

            $file = $root.'/'.$path;
            $contents = null;
            if (! str_starts_with($path, 'vendor/') && is_file($file) && (int) filesize($file) <= self::SCAN_LIMIT) {
                $contents = (string) @file_get_contents($file);
            }
            if (($problem = self::secretProblem($path, $contents, $allow)) !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private static function neverShipsName(string $name, string $path): bool
    {
        if (in_array($name, self::NEVER_SHIP_FILES, true)) {
            return true;
        }
        if (str_starts_with($name, '.env') && $name !== '.env.example') {
            return true;
        }
        if (str_starts_with($name, '.yarnrc')) {
            return true;
        }

        return ! str_starts_with(ltrim($path, '/'), 'vendor/') && preg_match('/\.sqlite3?$/i', $name) === 1;
    }
}
