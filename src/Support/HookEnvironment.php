<?php

namespace Slipstream\Support;

use Slipstream\Slipstream;

/**
 * The environment a hook and the detached deploy get.
 *
 * inherit: this process's whole environment — in a Laravel process that has
 * not cached its config, every .env value, APP_KEY and the database
 * credentials included — which is how deploys always ran and what git mode
 * keeps. minimal: PATH, HOME, the locale, proxy and CA settings, SLIPSTREAM_*
 * and whatever SLIPSTREAM_HOOK_ENV_PASS names; the default in artifact mode.
 *
 * What minimal buys is narrow: secrets stay out of hook output, which deploy
 * logs keep raw. It is not isolation — a hook runs as the deploy user, who
 * can read the shared .env — and artisan loads the .env itself anyway.
 *
 * Only hooks are trimmed. The detached deploy is Slipstream's own process,
 * and the one that downloads releases: it keeps its proxy and AWS settings.
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

    /** @var list<string> what a hook needs to reach anything at all: proxies and the CA they use */
    public const NETWORK_KEYS = ['HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'ALL_PROXY', 'SSL_CERT_FILE', 'SSL_CERT_DIR'];

    /** @var list<string> never handed to a hook: CI's signing key has no business on a node */
    public const NEVER = ['SLIPSTREAM_SIGNING_KEY'];

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
     * For a hook run through Symfony Process. Process merges every inherited
     * variable missing from the array back in and drops only those set to
     * false, so in minimal mode each variable that is not allowed is false.
     *
     * @return array<string, string|false>
     */
    public static function forHook(): array
    {
        $env = self::inherit();
        $minimal = self::mode() === self::MINIMAL;
        $allowed = self::allowed();

        foreach (self::inheritedKeys() as $key) {
            $upper = strtoupper($key);
            if (in_array($upper, self::NEVER, true) || ($minimal && ! in_array($upper, $allowed, true) && ! str_starts_with($upper, 'SLIPSTREAM_'))) {
                $env[$key] = false;
            }
        }

        return $env;
    }

    /**
     * This process's environment, for proc_open (where a key left out is
     * left out): the detached deploy.
     *
     * @return array<string, string>
     */
    public static function inherit(): array
    {
        $env = [];
        foreach (self::sources() as $source) {
            foreach ($source as $key => $value) {
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $env[$key] = $value;
                }
            }
        }

        $env['PATH'] = getenv('PATH') ?: ($env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin');

        $home = getenv('HOME') ?: ($env['HOME'] ?? null);
        if (! $home && function_exists('posix_getpwuid')) {
            $home = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        }
        if ($home) {
            $env['HOME'] = $home;
            $env['COMPOSER_HOME'] = $env['COMPOSER_HOME'] ?? ($home.'/.config/composer');
        }

        return $env;
    }

    /**
     * The names minimal mode keeps, upper-cased.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $pass = config('slipstream.deploy.hook_env_pass') ?? env('SLIPSTREAM_HOOK_ENV_PASS', '');
        $extra = is_array($pass) ? $pass : explode(',', (string) $pass);
        $extra = array_values(array_filter(array_map(fn ($name) => strtoupper(trim((string) $name)), $extra)));

        return array_values(array_unique([...self::MINIMAL_KEYS, ...self::NETWORK_KEYS, ...$extra]));
    }

    /**
     * Every variable a child could inherit from this process.
     *
     * @return list<string>
     */
    private static function inheritedKeys(): array
    {
        $keys = [];
        foreach (self::sources() as $source) {
            foreach (array_keys($source) as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * Where variables come from. Under FPM, $_SERVER holds the request's
     * headers (HTTP_COOKIE, HTTP_AUTHORIZATION) as well; only the CLI's is
     * the environment.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function sources(): array
    {
        $sources = [getenv() ?: [], $_ENV];
        if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            $sources[] = $_SERVER;
        }

        return $sources;
    }
}
