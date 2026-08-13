<?php

namespace Slipstream\Diagnostics;

/**
 * Reads the user PHP-FPM actually runs as.
 *
 * When that differs from whoever runs provision/deploy, everything the deploy
 * creates is unwritable by the app — which surfaces as blank 500s and an empty
 * laravel.log, because the logger cannot write the log that would name the
 * problem.
 */
class FpmPool
{
    /** Debian/Ubuntu, then RHEL/Alma/Rocky. */
    public const DEFAULT_GLOBS = [
        '/etc/php/*/fpm/pool.d/*.conf',
        '/etc/php-fpm.d/*.conf',
    ];

    /**
     * The first `user = …` from the FPM pool configs, or null when there is no
     * pool config to read (non-Linux, unusual layout, unreadable directory).
     *
     * A box with several PHP versions installed has several pool directories.
     * The configured service name (php8.4-fpm) says which one is actually
     * serving this app, so look there first and only then fall back to any.
     *
     * @param  list<string>|null  $globs  overridable for tests
     */
    public static function detectUser(?array $globs = null): ?string
    {
        foreach ($globs ?? self::globsForConfiguredService() as $glob) {
            foreach (glob($glob) ?: [] as $file) {
                $user = self::userFromConfig($file);
                if ($user !== null) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * Pool globs ordered so the PHP version named by slipstream.services.fpm
     * wins over any other version installed on the box.
     *
     * @return list<string>
     */
    public static function globsForConfiguredService(): array
    {
        $service = (string) config('slipstream.services.fpm', 'php8.4-fpm');

        // php8.4-fpm / php8.4-fpm.service / php-fpm8.4 → 8.4
        if (preg_match('/(\d+\.\d+)/', $service, $m) === 1) {
            return array_merge([
                '/etc/php/'.$m[1].'/fpm/pool.d/*.conf',
                '/etc/opt/remi/php'.str_replace('.', '', $m[1]).'/php-fpm.d/*.conf',
            ], self::DEFAULT_GLOBS);
        }

        return self::DEFAULT_GLOBS;
    }

    /**
     * The name of the user running this process, or null where POSIX lookups
     * are unavailable (Windows).
     */
    public static function currentUser(): ?string
    {
        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            return null;
        }

        return posix_getpwuid(posix_geteuid())['name'] ?? null;
    }

    /**
     * The owner of a path by name, or null when it cannot be resolved.
     */
    public static function ownerOf(string $path): ?string
    {
        if (! function_exists('posix_getpwuid')) {
            return null;
        }

        $uid = @fileowner($path);
        if ($uid === false) {
            return null;
        }

        return posix_getpwuid($uid)['name'] ?? null;
    }

    protected static function userFromConfig(string $file): ?string
    {
        if (! is_readable($file)) {
            return null;
        }

        foreach (preg_split("/\r\n|\n|\r/", (string) file_get_contents($file)) ?: [] as $line) {
            $line = trim($line);

            // ; and # both start comments in an FPM pool config.
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^user\s*=\s*(\S+)/', $line, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
