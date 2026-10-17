<?php

namespace Slipstream\Support;

/**
 * Validation for the shared-path map (release path => shared location). Both
 * sides are joined onto the layout root and the release directory, so a
 * leading slash or a `..` segment would point a symlink outside them.
 */
final class SharedPaths
{
    public static function normalize(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        return trim($path, '/');
    }

    public static function isUnsafe(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return true;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return true;
            }
        }

        return false;
    }
}
