<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard path
    |--------------------------------------------------------------------------
    |
    | URL path segment (no leading slash). Full URL is {app.url}/{path}
    | unless `domain` is set.
    |
    */
    'path' => env('SLIPSTREAM_PATH', 'slipstream'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard domain
    |--------------------------------------------------------------------------
    |
    | Optional host/subdomain that mounts the dashboard exclusively
    | (e.g. admin.example.com). Null = same host as the app.
    |
    */
    'domain' => env('SLIPSTREAM_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware stack for dashboard routes. The Slipstream authorization
    | gate is applied on top of this stack.
    |
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Local fallback access
    |--------------------------------------------------------------------------
    |
    | With no Slipstream::auth() callback and no `viewSlipstream` gate, the
    | dashboard is reachable in the `local` environment from the machine itself
    | only. That fence matters because the dashboard grants shell (deploy hook
    | scripts), the shared .env, and the webhook secret — so a dev server bound
    | to 0.0.0.0, published out of a container, or exposed through a share
    | tunnel would otherwise hand all of it to anyone who can reach the port.
    |
    | Set true to allow any origin in `local` (the pre-1.x behaviour). Prefer
    | registering a real gate instead.
    |
    */
    'local_open' => filter_var(env('SLIPSTREAM_LOCAL_OPEN', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Server name
    |--------------------------------------------------------------------------
    |
    | This node's identity in the fleet registry. Defaults to gethostname()
    | when null.
    |
    */
    'server_name' => env('SLIPSTREAM_SERVER'),

    /*
    |--------------------------------------------------------------------------
    | Application root (optional override)
    |--------------------------------------------------------------------------
    |
    | Leave null to auto-detect from the running app:
    |   - Normal Laravel: base_path() (where .env / artisan live)
    |   - Release layout: parent of current/ or releases/* (shared .env lives there)
    |
    | Only set SLIPSTREAM_APP_ROOT if your layout is non-standard.
    |
    */
    'app_root' => env('SLIPSTREAM_APP_ROOT'),

    /*
    |--------------------------------------------------------------------------
    | Deploy defaults
    |--------------------------------------------------------------------------
    |
    | Dashboard Settings (DB) can override these per fleet.
    |
    */
    'deploy' => [
        'branch' => env('SLIPSTREAM_BRANCH', 'main'),
        'keep_releases' => (int) env('SLIPSTREAM_KEEP_RELEASES', 5),
        'shared_paths' => [
            // Extra shared paths beyond .env and storage, e.g.:
            // 'public/images/patient' => 'shared/images/patient',
        ],
        'php_binary' => env('SLIPSTREAM_PHP', PHP_BINARY),
        'composer_binary' => env('SLIPSTREAM_COMPOSER', 'composer'),
        'npm_binary' => env('SLIPSTREAM_NPM', 'npm'),
        // Artisan cache command run after migrate. `optimize` caches config/
        // routes/views/events (recommended); `optimize:clear` clears them.
        'optimize_command' => env('SLIPSTREAM_OPTIMIZE_COMMAND', 'optimize'),
        'web_group' => env('SLIPSTREAM_WEB_GROUP', 'www-data'),
        'use_sudo' => env('SLIPSTREAM_USE_SUDO'), // null = auto (sudo when not root)

        /*
        | Fleet migration lock. The first eligible server to acquire this lock
        | runs `migrate --force`; the rest skip. In a multi-server fleet the
        | backing cache store MUST be shared across every node (redis, database,
        | memcached) — the file/array drivers give each server its own lock and
        | every server would migrate concurrently. Null = the app's default
        | cache store.
        */
        'migration_lock_store' => env('SLIPSTREAM_MIGRATION_LOCK_STORE'),
        // Lock is held for the whole migrate step; keep it above your longest
        // migration so a slow migrate never outlives its own lock.
        'migration_lock_ttl' => (int) env('SLIPSTREAM_MIGRATION_LOCK_TTL', 900),
        'migrate_timeout' => (int) env('SLIPSTREAM_MIGRATE_TIMEOUT', 600),
        // A deploy still "building" past this many minutes is treated as dead by
        // the prune watchdog and marked failed.
        'stuck_minutes' => (int) env('SLIPSTREAM_STUCK_MINUTES', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook ingress
    |--------------------------------------------------------------------------
    |
    | The hook endpoints are the only unauthenticated package routes. They are
    | HMAC/token verified, rate limited, and body-capped.
    |
    */
    'webhook' => [
        // Rate limit for the hook endpoints as "maxAttempts,perMinutes".
        'throttle' => env('SLIPSTREAM_WEBHOOK_THROTTLE', '60,1'),
        // Reject request bodies larger than this many bytes (0 = no cap).
        'max_body_bytes' => (int) env('SLIPSTREAM_WEBHOOK_MAX_BODY', 2_097_152),
        // Prune webhook delivery rows older than N days (0 = keep forever).
        'keep_delivery_days' => (int) env('SLIPSTREAM_KEEP_DELIVERY_DAYS', 30),

        // Webhook deploys are idempotent per commit: a delivery naming the SHA
        // this node already deployed successfully is ignored. That bounds replay
        // of a captured delivery (a provider HMAC signs only the body — no
        // timestamp — and the delivery id we dedupe on is an unsigned header).
        // Set true only if you rely on re-delivering a webhook to redeploy the
        // same commit; the dashboard's Redeploy button does that anyway.
        'allow_redeploy_same_sha' => filter_var(
            env('SLIPSTREAM_ALLOW_REDEPLOY_SAME_SHA', false),
            FILTER_VALIDATE_BOOLEAN
        ),

        'relay' => [
            // Fan out to peers in a detached process so the provider gets a
            // fast 200 even when peers are slow or unreachable.
            'detach' => filter_var(env('SLIPSTREAM_RELAY_DETACH', true), FILTER_VALIDATE_BOOLEAN),
            'timeout' => (int) env('SLIPSTREAM_RELAY_TIMEOUT', 5),
            // Reject relay pings whose signed timestamp falls outside this
            // window (replay guard), in seconds.
            'max_skew_seconds' => (int) env('SLIPSTREAM_RELAY_MAX_SKEW', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-swap services
    |--------------------------------------------------------------------------
    */
    'services' => [
        'fpm' => env('SLIPSTREAM_FPM_SERVICE', 'php8.4-fpm'),
        'horizon' => env('SLIPSTREAM_HORIZON_PROGRAM', 'horizon'),
        'reverb' => env('SLIPSTREAM_REVERB_PROGRAM', 'reverb'),

        // Reload PHP-FPM after the swap so opcache serves the new release.
        // Set false only for non-FPM runtimes (Octane, RoadRunner).
        'restart_fpm' => filter_var(env('SLIPSTREAM_RESTART_FPM', true), FILTER_VALIDATE_BOOLEAN),

        // Tri-state: true = always restart, false = never, null = auto — restart
        // only when the package is actually installed in the release. Auto (the
        // default) means apps without Horizon/Reverb never attempt-and-fail.
        'restart_horizon' => env('SLIPSTREAM_RESTART_HORIZON'),
        'restart_reverb' => env('SLIPSTREAM_RESTART_REVERB'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment event logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'keep_event_days' => (int) env('SLIPSTREAM_KEEP_EVENT_DAYS', 30),
        'max_event_line_bytes' => 8_192,
        'file_log' => true,
        // Max log lines the live viewer renders per poll. Bounds both the query
        // and the payload regardless of how many events a deploy produced.
        'viewer_tail' => (int) env('SLIPSTREAM_LOG_VIEWER_TAIL', 800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard sections
    |--------------------------------------------------------------------------
    |
    | Disable a view to hide its nav item and routes.
    |
    */
    'views' => [
        'deployments' => true,
        'servers' => true,
        'pipelines' => true,
        'hooks' => true,
        'webhook' => true,
        'settings' => true,
        'environment' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fleet
    |--------------------------------------------------------------------------
    */
    'fleet' => [
        // Mark a server offline if no heartbeat within this many seconds.
        'heartbeat_stale_seconds' => (int) env('SLIPSTREAM_HEARTBEAT_STALE', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared .env editor
    |--------------------------------------------------------------------------
    */
    'environment' => [
        // Keep N timestamped .env.slipstream.bak.* copies next to the shared .env.
        'backup_keep' => (int) env('SLIPSTREAM_ENV_BACKUP_KEEP', 10),

        // Master switch for multi-server env sync. When false: no encrypted
        // snapshots are published, the queue/nudge/apply path is inert, the
        // /hooks/env-sync endpoint 404s, and the Environment page collapses to
        // a this-server-only editor. Turn off if policy forbids .env blobs
        // (even encrypted) living in the shared database.
        'sync_enabled' => filter_var(env('SLIPSTREAM_ENV_SYNC', true), FILTER_VALIDATE_BOOLEAN),

        // Keys whose value is per-server and must never be broadcast. On a
        // fleet/multi-target push each target keeps its own line for these;
        // pushing e.g. SLIPSTREAM_SERVER verbatim would give every node the
        // same fleet identity. Comma-separated.
        'per_server_keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'SLIPSTREAM_ENV_PER_SERVER_KEYS',
                'SLIPSTREAM_SERVER,SLIPSTREAM_APP_ROOT,SLIPSTREAM_RESTART_HORIZON,SLIPSTREAM_RESTART_REVERB'
            ))
        ))),

        // Prune terminal (applied/failed/superseded/cancelled) push rows after
        // N days. Pending pushes to a server that never returns are expired to
        // cancelled after pending_max_days.
        'keep_push_days' => (int) env('SLIPSTREAM_ENV_KEEP_PUSH_DAYS', 30),
        'pending_max_days' => (int) env('SLIPSTREAM_ENV_PENDING_MAX_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live log updates
    |--------------------------------------------------------------------------
    */
    'realtime' => [
        'polling_ms' => 1_500,
        'broadcast' => env('SLIPSTREAM_BROADCAST', true),
    ],

];
