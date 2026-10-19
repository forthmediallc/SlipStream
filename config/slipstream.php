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
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The connection Slipstream keeps its own tables on: deployments and their
    | logs, webhook deliveries, the server registry the heartbeat refreshes,
    | hooks, pipelines, settings (the webhook secret, encrypted) and the
    | activity log. Null = the app's default connection.
    |
    | Give Slipstream a database of its own when the app's database should
    | hold nothing else — the CDE database on a PCI node, for instance. A
    | SQLite file in the shared storage/ directory is enough for one server.
    | The migrations follow this setting; on an existing install, move the
    | tables first with `php artisan slipstream:database copy --to=<name>`.
    |
    */
    'database' => [
        'connection' => env('SLIPSTREAM_DB_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deploy trigger
    |--------------------------------------------------------------------------
    |
    | webhook (the default): a push's provider webhook starts the deploy.
    | poll: each node checks the deploy ref from its minute heartbeat and
    | converges on it by itself. No inbound endpoint exists at all, which is
    | what an Auto Scaling group behind a load balancer needs — a webhook
    | lands on one node. README: "Poll mode and Auto Scaling groups".
    |
    */
    'trigger' => [
        'mode' => env('SLIPSTREAM_TRIGGER', 'webhook'),

        // What poll mode deploys: a branch (production, refs/heads/production)
        // or tags (refs/tags/v*, or just v*): the highest version wins. An
        // exact tag (refs/tags/v1.4.2) holds the fleet on that release.
        // Null = the deploy branch.
        'ref' => env('SLIPSTREAM_DEPLOY_REF'),

        // The repository a fresh node mirrors (slipstream:bootstrap). Takes
        // precedence over the dashboard setting when set.
        'repository' => env('SLIPSTREAM_REPOSITORY'),

        // Seconds between checks. The heartbeat runs once a minute, so values
        // below 60 behave as 60. Each check first waits a random 0..jitter
        // seconds so a fleet does not reach the git host in the same second.
        'poll_interval' => (int) env('SLIPSTREAM_POLL_INTERVAL', 60),
        'poll_jitter' => (int) env('SLIPSTREAM_POLL_JITTER', 20),

        // A commit whose deploy failed is retried after this many minutes,
        // doubling per failure up to the cap. A newer commit is tried at once.
        'retry_minutes' => (int) env('SLIPSTREAM_POLL_RETRY_MINUTES', 5),
        'retry_max_minutes' => (int) env('SLIPSTREAM_POLL_RETRY_MAX_MINUTES', 240),

        // Tag refs skip pre-releases (v2.0.0-rc.1) unless this is on.
        'prereleases' => filter_var(env('SLIPSTREAM_DEPLOY_PRERELEASES', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrate step
    |--------------------------------------------------------------------------
    |
    | With a command set, every deploy runs it in the new release before the
    | health check — exactly once per commit across every node that shares
    | the lock store (deploy.migration_lock_store). The other nodes wait for
    | that run and fail their deploy if it failed. Unset, there is no migrate
    | step and the seeded "Run migrations" hook works as it always has; set,
    | that hook stops running.
    |
    */
    'migrations' => [
        // An artisan command line, e.g. "migrate --force".
        'command' => env('SLIPSTREAM_MIGRATE_COMMAND'),

        // Whether this node may run it. Null = the server registry
        // (slipstream:servers set --run-migrations=…, on by default).
        'enabled' => env('SLIPSTREAM_RUN_MIGRATIONS'),

        // Seconds a node waits for another node's run of the same commit.
        // Null = the lock TTL plus a minute, so a crashed run is taken over.
        'wait_seconds' => env('SLIPSTREAM_MIGRATE_WAIT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Node settings from the environment
    |--------------------------------------------------------------------------
    |
    | The shared .env is the one thing every instance of an Auto Scaling
    | group has, so what a node must not forget lives here, not in its
    | database.
    |
    */
    'node' => [
        // Hold every node that reads this .env: no deploy starts. On top of
        // slipstream:servers pause, which holds one node.
        'paused' => filter_var(env('SLIPSTREAM_PAUSED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deploy hooks file
    |--------------------------------------------------------------------------
    |
    | A PHP file in the app repository returning a list of hooks, read from
    | each release as it is deployed, so a hook ships with the commit that
    | needs it and a fresh node runs exactly what an old one does. Relative to
    | the release root (an absolute path reads a file outside the repo). When
    | the file exists it replaces the seeded default hooks; hooks stored in the
    | database still run after its own.
    |
    */
    'hooks' => [
        'file' => env('SLIPSTREAM_HOOKS_FILE', 'slipstream-hooks.php'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification targets from the environment
    |--------------------------------------------------------------------------
    |
    | Any of these, when set, wins over the dashboard setting.
    |
    */
    'notifications' => [
        'mail' => env('SLIPSTREAM_NOTIFY_MAIL'),
        'slack_url' => env('SLIPSTREAM_NOTIFY_SLACK_URL'),
        'discord_url' => env('SLIPSTREAM_NOTIFY_DISCORD_URL'),
        'webhook_url' => env('SLIPSTREAM_NOTIFY_WEBHOOK_URL'),
        'on_success' => env('SLIPSTREAM_NOTIFY_ON_SUCCESS'),
        'on_failure' => env('SLIPSTREAM_NOTIFY_ON_FAILURE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PCI mode
    |--------------------------------------------------------------------------
    |
    | A locked-down profile for servers inside a PCI DSS cardholder data
    | environment (or any server where the dashboard must not exist). With it
    | on, this node registers ONE route — the provider webhook — and nothing
    | else: no dashboard, no Livewire components, no shared .env editor or
    | env sync (no snapshots, no pushes, no /hooks/env-sync), no relay fan-out
    | or /hooks/relay, and no fleet deploy queue (/hooks/deploy-sync, heartbeat
    | claiming). What remains is "receive a signed push, build a release, swap"
    | — and with the poll trigger on top, not even that route: the node finds
    | releases itself and registers none.
    |
    | Enable it with `php artisan slipstream:install --pci` (writes the env
    | line) or by setting SLIPSTREAM_PCI_MODE=true in the shared .env. It is
    | deliberately env-driven: with the dashboard, env editor and env sync all
    | off there is no path inside Slipstream that can flip it back — only
    | someone with shell access editing the file.
    |
    | PCI DSS v4.0.1 req. 2.2.4 (only necessary functions enabled) is the
    | control this serves. It does not take the server out of scope.
    |
    */
    'pci' => [
        'enabled' => filter_var(env('SLIPSTREAM_PCI_MODE', false), FILTER_VALIDATE_BOOLEAN),

        // Keys whose presence in the shared .env marks a server as part of the
        // CDE (vault database host, gateway credential ARNs, …). This file is
        // committed to the repo, so every server shares the list; doctor warns
        // when one of them is present and PCI mode is off.
        'sentinel_keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SLIPSTREAM_PCI_SENTINEL_KEYS', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Every webhook delivery outcome, deployment status change and dashboard/
    | CLI action already lands in the slipstream_* tables. This additionally
    | writes each one as a structured line to a Laravel log channel, so the
    | host's log shipping (syslog, CloudWatch, a SIEM) carries it off-box —
    | PCI DSS req. 10.3.3 wants audit logs promptly backed up to a central log
    | server, and a table the deploy user can UPDATE is not that.
    |
    | `enabled` null = on in PCI mode, off otherwise. `channel` null = the
    | app's default log channel.
    |
    */
    'audit' => [
        'enabled' => env('SLIPSTREAM_AUDIT_LOG'),
        'channel' => env('SLIPSTREAM_AUDIT_LOG_CHANNEL'),
    ],

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
        // A failed deploy keeps its half-built release as `<release>.failed` so
        // the failing command can be re-run where it failed. 0 = delete it.
        'keep_failed_releases' => (int) env('SLIPSTREAM_KEEP_FAILED_RELEASES', 1),
        'shared_paths' => [
            // Extra shared paths beyond .env and storage, e.g.:
            // 'public/images/patient' => 'shared/images/patient',
        ],
        // PHP CLI for deploys, hooks ({{ php }}) and the detached artisan
        // processes the dashboard and webhook spawn. Leave unset to auto-detect:
        // the CLI next to the running build (the version FPM serves), then
        // `php` on PATH. Set it only when PHP lives somewhere unusual — never
        // PHP_BINARY: from an FPM worker that is the daemon, which cannot run
        // artisan, and `slipstream:doctor` reports what will actually be used.
        'php_binary' => env('SLIPSTREAM_PHP'),
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
        // migration so a slow migrate never outlives its own lock. Slipstream
        // holds it at least migrate_timeout + 120s (and a fleet-locked hook's
        // lock at least the hour a hook may run + 120s) whatever this says.
        'migration_lock_ttl' => (int) env('SLIPSTREAM_MIGRATION_LOCK_TTL', 900),
        'migrate_timeout' => (int) env('SLIPSTREAM_MIGRATE_TIMEOUT', 600),
        // A deploy still "building" past this many minutes is treated as dead by
        // the watchdog (heartbeat, every minute) and marked failed. 0 disables.
        'stuck_minutes' => (int) env('SLIPSTREAM_STUCK_MINUTES', 120),
        // A deploy still "pending" past this many minutes never started (its
        // spawned process died before artisan ran) and is marked failed with
        // that diagnosis. Never longer than stuck_minutes; 0 = same window.
        'pending_stuck_minutes' => (int) env('SLIPSTREAM_PENDING_STUCK_MINUTES', 5),
        // A fleet deploy/rollback queued for a server that never claims it
        // (offline, paused, removed) expires after this many hours (0 = never).
        'queued_max_hours' => (int) env('SLIPSTREAM_QUEUED_MAX_HOURS', 24),
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

        // Source addresses the provider hook (/hooks/deploy) accepts, as IPs
        // or CIDR ranges, comma-separated. Empty = any source (the signature
        // is still verified). A delivery from anywhere else is rejected with
        // 403 and recorded in the delivery log before its body is read.
        //
        // GitHub publishes its hook ranges under "hooks" at
        // https://api.github.com/meta, Bitbucket Cloud at
        // https://ip-ranges.atlassian.com/ — both change over time, so refresh
        // the list when a delivery is rejected. Behind a proxy or load
        // balancer the app must trust it (TrustProxies) or the address seen
        // here is the proxy's. Restricting at nginx is equivalent.
        'allowed_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SLIPSTREAM_WEBHOOK_ALLOWED_IPS', ''))
        ))),

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

        // The daily prune forgets servers silent for this many hours (0 = never),
        // so instances an Auto Scaling group terminated stop piling up in a
        // shared registry.
        'forget_after_hours' => (int) env('SLIPSTREAM_FORGET_OFFLINE_AFTER_HOURS', 0),
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
