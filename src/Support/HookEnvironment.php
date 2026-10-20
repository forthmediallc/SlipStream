<?php

namespace Slipstream\Support;

use Slipstream\Slipstream;

/**
 * The environment a hook, a deploy step and the detached deploy get.
 *
 * inherit: this process's whole environment — in a Laravel process that is
 * every .env value, APP_KEY and the database credentials included — which
 * is how deploys always ran and what git mode keeps. minimal: PATH, HOME,
 * the locale, and SLIPSTREAM_*; artisan loads the .env itself. The default
 * in artifact mode, where the point is that third-party code never sees the
 * node's secrets.
 */
final class HookEnvironment
{
    public const INHERIT = 'inherit';

    public const MINIMAL = 'minimal';

    /** @var list<string> */
    public const MINIMAL_KEYS = [
        'PATH', 'HOME', 'COMPOSER_HOME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ', 'TMPDIR', 'USER', 'LOGNAME', 'SHELL',
        // Windows cannot start a process without these.
        'SYSTEMROOT', 'SYSTEMDRIVE', 'WINDIR', 'TEMP', 'TMP', 'COMSPEC', 'PATHEXT', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA',
    ];

    public static function mode(): string
    {
        // A published config that predates this key has no deploy.hook_env
        // (the merge is shallow); the variable still counts.
        $configured = strtolower(trim((string) (config('slipstream.deploy.hook_env') ?? env('SLIPSTREAM_HOOK_ENV', ''))));

        if (in_array($configured, [self::INHERIT, self::MINIMAL], true)) {
            return $configured;
        }

        return Slipstream::artifactMode() ? self::MINIMAL : self::INHERIT;
    }

    /**
     * @return array<string, string>
     */
    public static function build(): array
    {
        $env = [];
        $minimal = self::mode() === self::MINIMAL;

        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }
            if ($minimal && ! in_array(strtoupper($key), self::MINIMAL_KEYS, true) && ! str_starts_with($key, 'SLIPSTREAM_')) {
                continue;
            }
            $env[$key] = $value;
        }

        $env['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

        $home = getenv('HOME');
        if (! $home && function_exists('posix_getpwuid')) {
            $home = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        }
        if ($home) {
            $env['HOME'] = $home;
            $env['COMPOSER_HOME'] = $env['COMPOSER_HOME'] ?? ($home.'/.config/composer');
        }

        return $env;
    }
}
