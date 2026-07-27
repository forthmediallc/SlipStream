<?php

namespace Slipstream;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class Slipstream
{
    /**
     * The callback that determines who may access the dashboard.
     *
     * @var (Closure(Request): bool)|null
     */
    public static ?Closure $authUsing = null;

    /**
     * Application-registered health checks (name => callable).
     *
     * @var array<string, Closure(): void>
     */
    public static array $healthChecks = [];

    /**
     * Configure authorization for the Slipstream dashboard.
     *
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Determine if the given request may view the dashboard.
     *
     * Resolution order: an explicit Slipstream::auth() callback, then a
     * host-defined `viewSlipstream` gate, else the local fallback. The package
     * no longer defines the gate itself, so consulting it here cannot recurse.
     */
    public static function check(Request $request): bool
    {
        if (static::$authUsing !== null) {
            return (bool) (static::$authUsing)($request);
        }

        if (Gate::has('viewSlipstream')) {
            return (bool) Gate::forUser($request->user())->check('viewSlipstream', [$request]);
        }

        return static::localFallback($request);
    }

    /**
     * The no-gate-configured fallback: the dashboard is usable in `local`
     * without any setup, but only from the machine it runs on.
     *
     * The dashboard grants shell (hook scripts run on deploy), the shared `.env`,
     * and the webhook secret — so "local" alone is too weak a fence when the dev
     * server is bound to 0.0.0.0, published out of a container, or exposed
     * through a share tunnel. Set SLIPSTREAM_LOCAL_OPEN=true to opt back into
     * the old behaviour, or (better) register a real gate.
     */
    protected static function localFallback(Request $request): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        if (filter_var(config('slipstream.local_open', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return static::isLoopbackRequest($request);
    }

    /**
     * Did this request come from the machine itself, unproxied?
     *
     * A share tunnel (ngrok, expose, `herd share`) forwards from the local
     * machine, so the connecting address IS loopback — the forwarding headers
     * are what give it away. Laravel has already discarded X-Forwarded-* from
     * untrusted proxies by this point, so their presence means either a real
     * trusted proxy or a tunnel; neither is "someone sitting at this machine".
     */
    protected static function isLoopbackRequest(Request $request): bool
    {
        foreach (['forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-real-ip'] as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        $ip = (string) $request->ip();

        return $ip === '::1'
            || $ip === '::ffff:127.0.0.1'
            || str_starts_with($ip, '127.');
    }

    /**
     * Register a custom pre-swap health check.
     *
     * The callable should throw on failure or return void on success.
     *
     * @param  Closure(): void  $callback
     */
    public static function healthCheck(string $name, Closure $callback): void
    {
        static::$healthChecks[$name] = $callback;
    }

    /**
     * Resolved server name for this node.
     */
    public static function serverName(): string
    {
        return config('slipstream.server_name')
            ?: gethostname()
            ?: 'default';
    }

    /**
     * Absolute layout root for deploys (Envoyer-style parent).
     *
     * Prefer auto-detection from the running app path:
     * - Standard Laravel: base_path() (where .env lives)
     * - Release layout: parent of current/ or releases/<id>/ (shared .env lives there)
     *
     * Override only with config('slipstream.app_root') / SLIPSTREAM_APP_ROOT
     * when detection would be wrong (unusual layouts).
     */
    public static function appRoot(): string
    {
        $configured = config('slipstream.app_root');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR.'/\\');
        }

        return rtrim(static::detectAppRoot(), DIRECTORY_SEPARATOR.'/\\');
    }

    /**
     * Infer the deploy layout root from base_path().
     *
     * Layout we care about:
     *
     *   {app_root}/
     *     .env                 shared (always here in both modes)
     *     storage/             shared (release layout)
     *     repo.git/
     *     releases/<ts>_<sha>/
     *     current -> releases/…
     *
     * When the process is running from current/ or a release directory,
     * base_path() is NOT the layout root — the parent is.
     */
    public static function detectAppRoot(): string
    {
        $base = base_path();

        // …/current (Laravel base path often set to the symlink path)
        if (basename($base) === 'current') {
            return dirname($base);
        }

        // …/releases/<timestamp_sha>
        if (basename(dirname($base)) === 'releases') {
            return dirname(dirname($base));
        }

        // base_path() is the realpath of current (basename looks like a release folder)
        // and the parent already has the Envoyer layout markers.
        $parent = dirname($base);
        $hasReleases = is_dir($parent.DIRECTORY_SEPARATOR.'releases');
        $hasCurrent = is_link($parent.DIRECTORY_SEPARATOR.'current')
            || is_dir($parent.DIRECTORY_SEPARATOR.'current');
        $hasBareRepo = is_dir($parent.DIRECTORY_SEPARATOR.'repo.git');

        if ($hasReleases && ($hasCurrent || $hasBareRepo)) {
            return $parent;
        }

        // Normal Laravel app: .env, artisan, app/ all live at base_path().
        return $base;
    }

    /**
     * Path to the shared .env used across releases.
     *
     * Same place as Laravel expects for a normal app (app root); under a
     * release layout that is the parent of current/, not inside the release.
     */
    public static function sharedEnvPath(): string
    {
        return static::appRoot().DIRECTORY_SEPARATOR.'.env';
    }

    /**
     * Dashboard base path (no leading slash).
     */
    public static function path(): string
    {
        return trim((string) config('slipstream.path', 'slipstream'), '/');
    }

    /**
     * Whether a dashboard section is enabled. Defaults to true when the key is
     * absent so a config published before a section existed doesn't 404 a nav
     * link the layout still renders.
     */
    public static function viewEnabled(string $section): bool
    {
        return (bool) config("slipstream.views.{$section}", true);
    }

    /**
     * Ensure the package has been installed (config published / tables present).
     */
    public static function ensureConfigured(): void
    {
        if (! config()->has('slipstream.path')) {
            throw new RuntimeException(
                'Slipstream is not configured. Run: php artisan slipstream:install'
            );
        }
    }
}
