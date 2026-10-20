<?php

namespace Slipstream;

use Closure;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Slipstream\Fleet\NodeIdentity;

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
     * The poll-mode node name (see serverName()), worked out once per process.
     */
    public static ?string $nodeName = null;

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
     *
     * In PCI mode nobody passes, whatever the host configured: the dashboard
     * routes and components are not registered there, and this is the
     * backstop for anything that reaches a component some other way.
     */
    public static function check(Request $request): bool
    {
        if (static::pciMode()) {
            return false;
        }

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
     * This node's name in the fleet: SLIPSTREAM_SERVER when set, else the
     * hostname. Except in poll mode, where instances come and go and a reused
     * hostname would inherit another node's registry row and scheduler mutex:
     * there it is a name unique to the machine, such as the EC2 instance ID.
     */
    public static function serverName(): string
    {
        if ($configured = config('slipstream.server_name')) {
            return (string) $configured;
        }

        $hostname = gethostname() ?: 'default';

        if (! static::pollMode()) {
            return $hostname;
        }

        return static::$nodeName ??= app(NodeIdentity::class)->name($hostname);
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
     * Where the hook endpoints live (no leading slash): SLIPSTREAM_HOOKS_PATH,
     * else under the dashboard at {path}/hooks. Separate so an SSO proxy in
     * front of the dashboard can leave the git host's deliveries alone.
     */
    public static function hooksPath(): string
    {
        $configured = trim((string) config('slipstream.hooks_path', ''), '/');

        return $configured !== '' ? $configured : static::path().'/hooks';
    }

    /**
     * The path of one hook endpoint (deploy, relay, env-sync, deploy-sync).
     */
    public static function hookPath(string $endpoint): string
    {
        return static::hooksPath().'/'.ltrim($endpoint, '/');
    }

    /**
     * Its URL on this app, whether or not the route is registered here (poll
     * and PCI mode register fewer), which is what install and status show.
     */
    public static function hookUrl(string $endpoint): string
    {
        return url('/'.static::hookPath($endpoint));
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
     * Whether this node runs the locked-down PCI profile: provider webhook
     * only — no dashboard, no .env editor or sync, no peer traffic, no fleet
     * queue. See config/slipstream.php ("PCI mode") for the full list.
     *
     * Read from config so it is env-driven (SLIPSTREAM_PCI_MODE) and cached
     * with the rest of the config; nothing inside the package can change it.
     */
    public static function pciMode(): bool
    {
        return filter_var(config('slipstream.pci.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * How deploys start: a provider webhook, or each node polling its deploy
     * ref. Anything unrecognised reads as webhook, the safe default.
     */
    public static function triggerMode(): string
    {
        return strtolower(trim((string) config('slipstream.trigger.mode', 'webhook'))) === 'poll' ? 'poll' : 'webhook';
    }

    public static function pollMode(): bool
    {
        return static::triggerMode() === 'poll';
    }

    /**
     * Where releases come from: git (nodes build the deploy ref from the
     * mirror) or artifact (CI built, signed and stored them). Anything but
     * 'artifact' reads as git.
     */
    public static function releaseSource(): string
    {
        return strtolower(trim((string) config('slipstream.release.source', 'git'))) === 'artifact' ? 'artifact' : 'git';
    }

    public static function artifactMode(): bool
    {
        return static::releaseSource() === 'artifact';
    }

    /**
     * The environment releases are signed for; null when unset.
     */
    public static function releaseEnvironment(): ?string
    {
        $environment = trim((string) config('slipstream.release.environment', ''));

        return $environment === '' ? null : $environment;
    }

    /**
     * The connection Slipstream's tables live on (SLIPSTREAM_DB_CONNECTION);
     * null means the app's default connection.
     */
    public static function databaseConnection(): ?string
    {
        $name = config('slipstream.database.connection');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * Schema builder for Slipstream's connection — what every "does this
     * table exist yet" check must ask, or it asks the app's database.
     */
    public static function schema(): Builder
    {
        return Schema::connection(static::databaseConnection());
    }

    /**
     * Source addresses the provider hook accepts (IPs / CIDRs); empty = any.
     * Tolerates a stale published config without the key and a string value.
     *
     * @return list<string>
     */
    public static function webhookAllowedIps(): array
    {
        $configured = config('slipstream.webhook.allowed_ips', []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        return array_values(array_filter(array_map('trim', (array) $configured)));
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
