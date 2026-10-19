# Slipstream

Zero-downtime deploys for Laravel — self-hosted, no SSH to your servers.

Slipstream installs into your app. On push (or from the dashboard), each server builds a fresh release, runs health checks, then atomically swaps traffic to the new code. Failed builds never go live. A gated dashboard shows fleet status, live logs, webhooks, and settings.

## Requirements

- PHP 8.2+ (Laravel 13 requires 8.3+)
- Laravel 11, 12, or 13
- Livewire 3.8.3+ or 4.3.4+ — earlier releases carry a DOM-based XSS advisory, which matters here
  because the dashboard edits deploy hooks (shell on every server)
- Linux in production (`symlink` swap, `systemctl`, `setsid`)
- Shared database across servers (multi-server); cache driver that supports locks (Redis or database) for fleet migrations

## Installation

```bash
composer require slipstream/slipstream
php artisan slipstream:install
```

For a server inside a PCI cardholder data environment, or anywhere the dashboard must not
exist, install with `--pci` instead — see [PCI mode](#pci-mode). For an Auto Scaling group,
or any fleet a webhook can't reach node by node, see
[Poll mode and Auto Scaling groups](#poll-mode-and-auto-scaling-groups).

### Authorize the dashboard

The dashboard is **closed by default in production** and only open automatically in the
`local` environment. You decide who gets in one of two ways:

**Option A — an authorization callback** (register in `AppServiceProvider::boot()`):

```php
// AppServiceProvider::boot()
use Slipstream\Slipstream;

Slipstream::auth(function ($request) {
    return $request->user()?->isAdmin();
});
```

**Option B — a `viewSlipstream` gate** (if you already organize access with gates/policies):

```php
// AppServiceProvider::boot()
use Illuminate\Support\Facades\Gate;

Gate::define('viewSlipstream', fn ($user) => $user->isAdmin());
```

`Slipstream::check($request)` resolves access in this order: the `Slipstream::auth()` callback
if set → otherwise a `viewSlipstream` gate if one is defined → otherwise **`local`, from the
machine itself only**. The package never defines the gate itself, so nothing is exposed until
you opt in.

That last fallback is narrow on purpose: the dashboard grants shell (deploy hook scripts run
on the server), the shared `.env`, and the webhook secret. `local` alone is not a fence when
the dev server is bound to `0.0.0.0`, published out of a container, or exposed through a share
tunnel (`herd share`, ngrok, expose) — so the request must also arrive unproxied from
loopback. Set `SLIPSTREAM_LOCAL_OPEN=true` if you genuinely need the old any-origin behaviour
in `local`; registering a gate is better.

**How the gate is enforced (defence in depth).** The check runs at three layers, so a mutation
can never be driven from a stale page without re-passing it:

1. **Route middleware** (`AuthorizeSlipstream`) guards the initial full-page `GET` of every
   dashboard route.
2. **Livewire persistent middleware** re-applies the same gate on every `POST /livewire/update`,
   so component actions can't run from a captured snapshot.
3. **Per-component `boot()`** re-checks on every hydration/action (deploy, rollback, hook edits,
   `.env` writes/pushes, secret reveal) as a final backstop.

**The exception — hook endpoints.** `/hooks/deploy`, `/hooks/relay`, `/hooks/env-sync`, and
`/hooks/deploy-sync` are intentionally *not* behind the dashboard gate: they are
unauthenticated, machine-to-machine POSTs verified by HMAC signature/secret (see
[Security](#security)). Everything else requires passing the gate above. In
[poll mode](#poll-mode-and-auto-scaling-groups) `/hooks/deploy` and `/hooks/relay` are not
registered at all.

Optional pre-swap checks (throw on failure):

```php
Slipstream::healthCheck('custom', function () {
    // …
});
```

### Config

Published as `config/slipstream.php`:

| Option | Purpose |
|---|---|
| `path` | Dashboard URL segment (default `slipstream`) |
| `domain` | Optional subdomain for the dashboard |
| `middleware` | Route middleware (default `['web']`) |
| `local_open` | Allow any origin in `local` when no gate is set (`SLIPSTREAM_LOCAL_OPEN`, default off) |
| `database.connection` | Connection for Slipstream's own tables; null = the app default (`SLIPSTREAM_DB_CONNECTION`). See [Slipstream's own database](#slipstreams-own-database) |
| `webhook.allow_redeploy_same_sha` | Let a webhook redeploy the commit already live here (default off) |
| `server_name` | This node’s fleet identity (`SLIPSTREAM_SERVER`) |
| `views.*` | Enable/disable dashboard sections |
| `deploy.keep_releases` | How many releases to retain (default 5) |
| `deploy.keep_failed_releases` | How many failed releases to keep as `<release>.failed` for inspection (default 1, `0` deletes them) |
| `deploy.php_binary` | PHP CLI for deploys and the processes the dashboard/webhook spawn (`SLIPSTREAM_PHP`); auto-detected when unset, never php-fpm |
| `deploy.pending_stuck_minutes` | Fail a deploy still `pending` after N minutes — its process never started (default 5) |
| `pci.enabled` | [PCI mode](#pci-mode): provider webhook only, no dashboard / `.env` editor / env sync / peer traffic (`SLIPSTREAM_PCI_MODE`) |
| `pci.sentinel_keys` | `.env` keys that mark a CDE server; `doctor` fails when one is present and PCI mode is off (`SLIPSTREAM_PCI_SENTINEL_KEYS`) |
| `webhook.allowed_ips` | IPs/CIDRs the provider hook accepts deliveries from; empty = any (`SLIPSTREAM_WEBHOOK_ALLOWED_IPS`) |
| `audit.enabled` / `audit.channel` | Structured audit lines to a log channel — on in PCI mode by default (`SLIPSTREAM_AUDIT_LOG`, `SLIPSTREAM_AUDIT_LOG_CHANNEL`) |
| `trigger.*` | `webhook` (default) or `poll`, the deploy ref, the repository, poll interval, jitter and back-off (`SLIPSTREAM_TRIGGER`, `SLIPSTREAM_DEPLOY_REF`, …). See [Poll mode](#poll-mode-and-auto-scaling-groups) |
| `migrations.*` | The migrate step: a command run once per commit across the fleet, whether this node runs it, how long others wait (`SLIPSTREAM_MIGRATE_COMMAND`, `SLIPSTREAM_RUN_MIGRATIONS`, `SLIPSTREAM_MIGRATE_WAIT`) |
| `node.paused` | Hold every node that reads this `.env`: no deploy starts (`SLIPSTREAM_PAUSED`) |
| `hooks.file` | Deploy hooks file the app repository carries, read from each release (`SLIPSTREAM_HOOKS_FILE`, default `slipstream-hooks.php`) |
| `notifications.*` | Notification targets from the environment; each wins over the stored setting when set (`SLIPSTREAM_NOTIFY_*`) |
| `fleet.forget_after_hours` | The daily prune forgets servers silent this many hours; 0 = never (`SLIPSTREAM_FORGET_OFFLINE_AFTER_HOURS`) |

Re-publish after package upgrades:

```bash
php artisan slipstream:publish              # config + assets
php artisan slipstream:publish --config
php artisan slipstream:publish --assets
```

## First deploy (production layout)

Slipstream uses a release layout on disk:

```
{app_root}/
  .env                 # shared
  storage/             # shared
  repo.git/            # bare mirror
  releases/<ts>_<sha>/
  current -> releases/…
```

The layout root is auto-detected (`current/` or `releases/*` → parent; otherwise `base_path()`). Override with `SLIPSTREAM_APP_ROOT` only if needed.

```bash
php artisan slipstream:provision --repo=git@github.com:org/app.git
# Create the shared .env at the layout root once (production values, APP_KEY, etc.)
php artisan slipstream:deploy
```

Point your web server document root at `{app_root}/current/public`, and add the
`$realpath_root` fastcgi params — see `stubs/nginx-slipstream.conf`. They are required with
symlinked releases: without them FPM keys opcache on the unchanging `current/...` path and
keeps serving the previous release after a swap.

Run `php artisan slipstream:doctor` before the first deploy. It checks ownership, the layout,
the PHP binary deploys are spawned with, sudo, git access, and webhook reachability in one pass,
and names the fix for anything it finds.

### Adopting a site that is already live

If the app is already serving from a plain checkout at `{app_root}/public`, there is nothing to
point a document root at yet: `current` only exists after a successful deploy. Rather than take
the site down while you restructure, let provision adopt the checkout:

```bash
php artisan slipstream:provision --repo=git@github.com:org/app.git --adopt
```

That copies the live tree as-is into `releases/<ts>_<sha>`, links the shared `.env` and
`storage/` the way a deployed release links them, and points `current` at it. No composer, no
hooks, no health gate — the release is byte-identical to what is serving, so both document roots
serve the same bytes and the nginx flip is zero-downtime and can wait until you are ready.

Configured `shared_paths` are handled properly: the live data is moved into its shared location
and **both** roots are linked at it — the release and the old document root — so uploads taken
before the flip and after it land in the same place and survive the next deploy. (This is also
the only thing that seeds a shared path from an existing site — a plain first deploy creates it
empty, because the directory is usually gitignored.)

Afterwards the app files left at the layout root are inert (the root is detected from the layout
markers), and the adopted release ages out through normal keep-N pruning.

Note: `current` must not already exist as a real directory. The deploy swap replaces it with
`mv -T`, which fails against one.

Ensure the scheduler runs (heartbeats and future scheduled work):

```cron
* * * * * cd /path/to/current && php artisan schedule:run >> /dev/null 2>&1
```

## Webhooks

Dashboard → **Webhook** shows the endpoint and secret for this server. (In
[poll mode](#poll-mode-and-auto-scaling-groups) there is no webhook: each node finds new
releases itself.)

- **URL:** `POST https://your-app/slipstream/hooks/deploy` (path is configurable)
- **GitHub:** push events, secret = HMAC (`X-Hub-Signature-256`)
- **GitLab:** Push Hook, secret = token (`X-Gitlab-Token`)
- **Bitbucket:** repo:push, secret = HMAC (`X-Hub-Signature`)

### One webhook for the whole fleet

1. On each peer, set **Servers → Edit → Base URL** (directly addressable origin).
2. On the node that receives the git webhook, enable **Relay mode**.
3. That node fans out a signed request to each peer’s `/hooks/relay`.

Peer traffic (relay, env-sync nudges, deploy-sync nudges) is signed with the **fleet key**,
not the webhook secret — see [Two secrets](#two-secrets).

Without relay, add the same webhook URL once per server (each must be reachable without a single shared load-balanced VIP stealing the hook).

## Dashboard

Default: `/slipstream`

| Section | What it does |
|---|---|
| **Deployments** | Fleet timeline, deploy now, rollback, live logs — deploy/redeploy/rollback act on **every eligible server** |
| **Servers** | Heartbeats, pause/resume, drift, per-server services |
| **Pipelines** | Branch patterns, auto-deploy, which servers participate |
| **Webhook** | Endpoint, secret, delivery log, relay |
| **Settings** | Repo, keep-N, shared paths, notifications, hooks |
| **Environment** | Guarded editor for the shared `.env` — view/edit any server’s file and push to this server, selected servers, or the whole fleet |

**Fleet-wide actions.** The Deploy modal (all servers by default, or this server only),
Redeploy, and Rollback act on every enabled, unpaused server — no matter which node happens
to serve the dashboard request. The serving node runs its deployment immediately; each peer
gets a `queued` deployment row in the shared DB, an instant signed nudge if it has a
**Base URL**, and its minute heartbeat as the reliable backstop (a missed nudge only costs
latency). Rollback repoints each server at **its own** previous release — identical SHAs
after a uniform deploy; Redeploy pins one SHA fleet-wide, which also makes it the cleanest
way to converge a drifted fleet. A queued action no server claims (offline, paused, removed)
expires after `SLIPSTREAM_QUEUED_MAX_HOURS` (default 24).

Rollback is **code only** — it does not reverse database migrations. Prefer expand→contract migrations.

## Artisan commands

| Command | Purpose |
|---|---|
| `slipstream:install` | Publish assets, migrate, seed defaults; `--pci` turns on [PCI mode](#pci-mode) for this server |
| `slipstream:publish` | Re-publish config / assets / migrations |
| `slipstream:status` | Fleet overview: servers and drift, in-flight and latest deployments, failures in the last 24h, pipelines (`--json`) |
| `slipstream:deployments` | List deployments, newest first: `--active`, `--failed`, `--status=`, `--server=this`, `--limit=` (`--json`) |
| `slipstream:deployment` | One deployment with its log; `--follow` tails a running one and exits non-zero if it fails (`--tail=`, `--json`) |
| `slipstream:deliveries` | Recent webhook deliveries and their outcome — "why didn't my push deploy?" (`--json`) |
| `slipstream:hooks` | Deploy hooks: `list` (stored and [file](#hooks-from-the-repository) hooks, with where each comes from), `show <id>`, `add`, `edit <id>`, `enable`/`disable <id>`, `delete <id>`, `move <id> up\|down` |
| `slipstream:servers` | Fleet servers: `list`, `pause`/`resume` (`resume` also lifts a [pin](#rollback-in-poll-mode)), `enable`/`disable`, `set --base-url=… --run-migrations=…`, `forget <name>` |
| `slipstream:pipelines` | Pipelines: `list`, `add --name= --branch=`, `edit`, `enable`/`disable`, `delete` |
| `slipstream:settings` | `show`, or `set --repo= --branch= --keep-releases= --shared-path=in:on --mail= --slack-url= … --relay=on\|off` |
| `slipstream:webhook-secret` | Show (`--reveal`) or replace (`--rotate`) the webhook secret; `--rotate-fleet-key` separates or rotates the fleet key |
| `slipstream:database` | Where Slipstream's records live: `status`, `copy --to=<connection>`, `drop-source` — moves them off the app database; `ensure` brings a node's own Slipstream database up to this version's schema |
| `slipstream:provision` | Deploy key, bare mirror, directory layout; `--adopt` converts a live checkout into the first release |
| `slipstream:bootstrap` | Bring a new instance up: tables, registry, mirror, the deploy ref deployed and health-checked; exits non-zero unless the node can serve. See [Bootstrapping a new instance](#bootstrapping-a-new-instance) |
| `slipstream:doctor` | Diagnose layout, ownership, PHP binary, sudo, git access, webhook reachability and the PCI-mode checks — with the fix for each |
| `slipstream:deploy` | Run a deploy here (or `--deployment=`); `--sha=` pins a commit, `--tag=` deploys a tag, `--skip-hooks=1,2` skips hooks once, `--fleet` is the dashboard's fleet-wide Deploy. In poll mode, with nothing named, it deploys the deploy ref's commit now |
| `slipstream:rollback` | Point `current` at the previous release; `--fleet` rolls every eligible server back to its own previous release. In poll mode it also pins the node |
| `slipstream:healthcheck` | Pre-swap checks (DB, Redis, custom); prints the release it ran in |
| `slipstream:heartbeat` | Refresh this node in the fleet registry, publish its `.env` snapshot, apply any queued env push, and fail deploys orphaned by a killed process; in poll mode, check the deploy ref and start a deploy when it moved (scheduled every minute) |
| `slipstream:prune` | Trim old deploy events + webhook deliveries + terminal env pushes; fail stuck deploys/applies; forget long-silent servers when `SLIPSTREAM_FORGET_OFFLINE_AFTER_HOURS` is set (scheduled daily) |
| `slipstream:relay` | Fan a stored relay payload out to peers (spawned detached; not run by hand) |
| `slipstream:env-apply` | Apply this node’s queued shared `.env` push (spawned detached by a nudge; also the manual escape hatch) |
| `slipstream:env-nudge` | Nudge peers to apply a queued env push (spawned detached; not run by hand) |
| `slipstream:deploy-nudge` | Nudge peers to run a queued fleet deploy/rollback (spawned detached; not run by hand — the heartbeat claims queued rows anyway) |

Manual escape hatch if the app is hard-down: run `php artisan slipstream:deploy` over SSH/console.

## Multi-server notes

- All nodes share the **same application database** (`slipstream_*` tables).
- **Dashboard deploy/redeploy/rollback are fleet-wide.** Whichever node serves the request
  runs its own deployment immediately and writes a `queued` deployment row per peer; peers
  claim their row via an instant signed nudge (Base URL set) or their minute heartbeat.
  There is no cross-server barrier: nodes swap at slightly different moments, so keep
  changes compatible across that window (you already need this for webhook deploys).
  Branch deploys resolve the branch tip per node at build time; use Redeploy (pinned SHA)
  when you need guaranteed convergence.
- Only one migration-eligible server runs `migrate --force` per commit (cache lock). In a
  multi-server fleet this lock **must** live on a shared cache store (Redis, database,
  memcached) — with the `file`/`array` driver each node gets its own lock and all of them
  migrate. Pin it with `SLIPSTREAM_MIGRATION_LOCK_STORE` if your default store isn't shared.
  The seeded hook only keeps two servers from migrating *at the same moment*; for "exactly
  once per commit, and the others wait for it", set `SLIPSTREAM_MIGRATE_COMMAND` — see
  [Migrations once per commit](#migrations-once-per-commit). A fleet-locked hook's lock is
  held for at least the hour a hook may run, plus two minutes, so a slow migration never
  outlives it.
- Pause a server to accept webhooks but skip builds (in poll mode: checks go on, no deploy
  starts).
- Notifications (mail, Slack, Discord, generic webhook) are configured under Settings.
- **Horizon and Reverb restarts are auto-detected** — a deploy only restarts them when the
  package is actually installed in the release. Force or disable per node with
  `SLIPSTREAM_RESTART_HORIZON` / `SLIPSTREAM_RESTART_REVERB` (`true`/`false`).
- Relay fan-out is signed with a domain-separated, timestamped HMAC (replay-protected) and
  dispatched in a detached process, so the git provider always gets a fast `200`.
- **Scoped `.env` updates.** The Environment editor can write this server only, selected
  servers, or the whole fleet. Cross-server pushes are queued in the shared DB (the source
  of truth) and applied by each node’s minute heartbeat; peers with a **Base URL** also get
  an instant, signed nudge (same HMAC scheme as relay, its own domain tag) so changes land
  in seconds. The nudge is content-free ("check your queue") — the heartbeat is the reliable
  backstop, so a missed nudge only costs latency.
- Each node publishes an **encrypted snapshot** of its `.env` to the shared DB (so the
  dashboard can view/edit any server and flag env drift). Because these snapshots and the
  existing encrypted settings ride `APP_KEY`, the fleet **must share one `APP_KEY`**.
- Fleet/multi-target pushes are a **full-file replace that preserves per-server keys** —
  `SLIPSTREAM_SERVER` and other per-node values keep each node’s own line
  (`SLIPSTREAM_ENV_PER_SERVER_KEYS`, comma-separated). Editing a single server writes exactly
  what you save (no merge).
- Turn the whole feature off with `SLIPSTREAM_ENV_SYNC=false`: no snapshots are stored, the
  queue/nudge/apply path is inert, the `/hooks/env-sync` route `404`s, and the editor
  collapses to a this-server-only tool.

## Troubleshooting

Start with `php artisan slipstream:doctor` — it checks everything below at once and prints the
fix next to each problem. The entries here explain the symptoms, which rarely resemble the cause.

**GitHub returns `419 Page Expired`.** The host app's CSRF middleware is intercepting the hook.
Slipstream excludes the hook paths both per-route and through the framework's static exclusion
list, which covers subclasses of Laravel's middleware — but an app with its own CSRF middleware
that doesn't extend Laravel's is beyond a package's reach. Add the exclusion yourself in
`bootstrap/app.php`:

```php
$middleware->validateCsrfTokens(except: ['slipstream/hooks/*']);
```

`slipstream:doctor` POSTs to your own hook URL and reports which case you are in: `401` means
the endpoint is healthy and enforcing signatures, `419` means CSRF is still in the way. That
unsigned POST cannot trigger anything — the signature is verified before a pipeline is even
looked up — but it does leave one rejected entry in the delivery log each run.

**The browser shows `No input file specified`, or 500s are blank with an empty `laravel.log`.**
Both are permission problems wearing a disguise. If the web server cannot traverse `releases/`
(e.g. it is `0750` and owned by root), `$realpath_root` resolves to an empty string and FPM is
handed a bare `/index.php`. If `storage/` is not writable by the pool user, Monolog cannot write
the log that would have named the problem. Deploys must run as the FPM pool user; `provision`
and `doctor` both compare the two and print the `chown` to run.

**A deploy fails at git fetch with `insufficient permission for adding an object to repository
database …/repo.git/objects`.** The user running the deploy cannot write into the mirror. Dashboard
and webhook deploys run as the FPM pool user, and `repo.git` was usually cloned by whoever ran
`provision` — root, typically. Hand the whole mirror (and the rest of what a deploy writes) to the
pool user, then keep every deploy on that user:

```bash
sudo chown -R deploy:deploy /var/www/app/repo.git /var/www/app/releases /var/www/app/deploy
sudo -u deploy php artisan slipstream:deploy      # never plain sudo
```

The sneaky version: `repo.git` *looks* owned correctly, but a single fetch that once ran as root left
root-owned `objects/xx/` directories inside it, and every later fetch dies the moment it needs one
of them. `slipstream:doctor` checks every directory in the mirror, not just the top, and its "git
mirror" check reports whose key it tested — a root-run doctor proves nothing about what the pool
user can fetch, so run it as the deploy user for the real answer.

**A deploy fails with `There are no commands defined in the "slipstream" namespace`.** The
deployed commit predates the package. Releases are built from the mirrored branch, not from your
working directory, so `composer require` alone is not enough — and neither is committing without
pushing:

```bash
git add composer.json composer.lock && git commit -m "Add Slipstream" && git push
```

`slipstream:doctor` answers this against the bare mirror at the configured branch, which is
exactly what the next deploy installs, so an unpushed commit is reported rather than passed.

**A release fails and you want to look at it.** The failed release is kept as
`releases/<ts>_<sha>.failed` (`SLIPSTREAM_KEEP_FAILED_RELEASES`, default 1) with its shared
links intact, so you can `cd` into it and re-run the failing command in the environment it
failed in. Note that a rollback never selects one of these.

**A deploy is stuck showing `building`.** Interrupted deploys record themselves as failed on
SIGINT/SIGTERM; a process killed outright (SIGKILL, OOM, reboot) is caught by the watchdog in
the minute heartbeat, after `SLIPSTREAM_STUCK_MINUTES` (default 120). A row stuck in `pending`
never started at all, so it is failed much sooner, after `SLIPSTREAM_PENDING_STUCK_MINUTES`
(default 5) — see the next entry for the usual cause.

**A webhook or dashboard deploy sits in `pending`, and `deploy/logs/detach-*.log` holds only
php-fpm usage text.** The deploy was launched with the wrong interpreter. Dashboard and webhook
deploys are spawned from a PHP-FPM worker, and there PHP's own `PHP_BINARY` is the FPM daemon,
which cannot run artisan: it prints its help and exits, while the shell that backgrounded it
reports success. Slipstream therefore resolves the CLI itself — an explicit `SLIPSTREAM_PHP`,
else the CLI matching FPM's version next to the running build, else `php` on `PATH` — and refuses
to launch anything that looks like php-fpm or php-cgi, failing the row immediately instead. If
PHP lives somewhere it cannot find, set `SLIPSTREAM_PHP=/usr/bin/php8.4` in the shared `.env`.
`slipstream:doctor` reports the binary those deploys will use and flags a config published before
auto-detection, which defaulted `php_binary` to `PHP_BINARY` (re-publish it with
`php artisan slipstream:publish --config`). The first line of every detach log records the exact
command that was launched.

## Security notes

- The hook endpoints (`/hooks/deploy`, `/hooks/relay`, `/hooks/env-sync`, `/hooks/deploy-sync`)
  are the only unauthenticated routes: HMAC/token verified, CSRF-exempt (so provider POSTs work
  out of the box), rate limited, and body-capped. No host-app CSRF configuration is required in
  the common case — if your app registers its own CSRF middleware that doesn't extend Laravel's,
  see [Troubleshooting](#troubleshooting).
  The env-sync and deploy-sync nudges carry no content — each is a signed, replay-window-
  bounded, domain-separated "check your queue"; what actually runs travels only through the
  shared database.
- The dashboard gate is enforced on the initial page load **and** on every Livewire action
  (persistent middleware + an in-component check), so actions can't be driven from a stale
  page snapshot.
- Every state-changing dashboard action (deploy, rollback, setting/pipeline/hook/server
  change, `.env` edit) records the acting user in `slipstream_activity_log`.

## Security

- Dashboard is gated; the only unauthenticated package routes are the webhook + env-sync + deploy-sync endpoints, verified by secret/HMAC.
- Sensitive settings, secrets, and per-node `.env` snapshots/pushes use Laravel encryption (`APP_KEY`).
- Prefer a non-root deploy user with scoped sudo for FPM/supervisor reloads — see `stubs/sudoers-slipstream`.
  Service reloads run `sudo -n`, so a missing NOPASSWD rule fails immediately with a warning
  instead of blocking a detached deploy on a password prompt it can never answer. `provision`
  and `doctor` both check the rule is in place.
- Deploy logs (`{app_root}/deploy/logs/*.log`) are created `0600`: hook output is stored
  verbatim, in that file and in `slipstream_deployment_events`, and it routinely echoes
  secrets. Nothing redacts it.
- **Webhook deploys are idempotent per commit.** A provider HMAC signs the body and nothing
  else — no timestamp — and the delivery id used for de-duplication rides in an unsigned
  header, so a captured delivery could otherwise be replayed forever. A delivery naming the
  SHA this node already deployed successfully is ignored. Retrying a *failed* deploy with the
  provider's "redeliver" button still works, as does rolling forward after a rollback. Set
  `SLIPSTREAM_ALLOW_REDEPLOY_SAME_SHA=true` to opt out (the dashboard's Redeploy button does
  the same job).
- Branch and tag names are validated before they reach `git` (`Slipstream\Support\GitRef`).
  Every git call is array-form — no shell — so the concern is argument injection: git parses
  options after positional arguments, and a ref beginning with `-` would be read as one.

### Two secrets

Slipstream keeps the value you hand to your git host separate from the one your servers
trust each other with:

| Key | Used for | Who sees it |
|---|---|---|
| **Webhook secret** | GitHub HMAC, GitLab token, Bitbucket HMAC | pasted into your git host — GitLab transmits it verbatim in every `X-Gitlab-Token` header |
| **Fleet key** | relay fan-out + env-sync/deploy-sync nudges between your own nodes | never leaves the fleet; each node reads it from the shared DB |

They are separate because a relay push carries an arbitrary commit SHA and pipeline id — if
one secret did both jobs, anyone who obtained the webhook secret at the provider (a git-host
admin, a header-logging proxy, a leaked CI variable) could deploy any commit in your mirror
on every node.

Fresh installs get both from `slipstream:install`. **Installs that predate the split keep
using the webhook secret for peer traffic** so a fleet mid-upgrade doesn't break; separate
them from Dashboard → **Webhook → Fleet key → Generate separate fleet key** once every node
runs this version. Rotating the fleet key needs no provider changes — peers pick it up from
the shared database immediately.

## Slipstream's own database

Slipstream keeps its records in its own `slipstream_*` tables: deployments and their logs,
webhook deliveries, the server registry the minute heartbeat refreshes, hooks, pipelines, the
activity log, and settings — the webhook secret among them, encrypted. By default they live on
the app's default database connection. Set `SLIPSTREAM_DB_CONNECTION` to give them one of
their own, so an app database that should hold nothing else — the CDE database on a
[PCI](#pci-mode) node — doesn't.

Define the connection in `config/database.php`. For one server, a SQLite file in the shared
`storage/` directory is enough. Every release links that directory, so the file survives
deploys:

```php
'slipstream' => [
    'driver' => 'sqlite',
    'database' => env('SLIPSTREAM_DB_DATABASE', storage_path('slipstream.sqlite')),
    'foreign_key_constraints' => true,
    'busy_timeout' => 5000,    // the webhook, a deploy and the heartbeat write concurrently
    'journal_mode' => 'wal',
],
```

A fleet that shares its records needs a shared database instead, a small MySQL or Postgres
database of its own. A PCI node shares nothing with the fleet, so a per-node SQLite file suits
it. Either way the migration lock still needs a shared cache store.

**On a fresh install**, set `SLIPSTREAM_DB_CONNECTION` before running `slipstream:install`.
The migrations create the tables on that connection, and the app's `migrations` table only
records that they ran, the same arrangement Telescope and Pulse use.

**On an existing install**, the tables are already on the default connection and the
migrations already count as run. Changing the setting alone would point Slipstream at an empty
database, so move the tables instead, between pushes:

```bash
php artisan slipstream:database copy --to=slipstream    # create the tables there, copy every row
# set SLIPSTREAM_DB_CONNECTION=slipstream in the shared .env, then deploy
#   (or run config:cache from current/ and reload PHP-FPM)
php artisan slipstream:database status                  # check the counts
php artisan slipstream:database drop-source             # remove the old set from the app database
```

The copy runs while the app still uses the old connection. Ids are kept, and the webhook
secret, hooks, pipelines and history all come along (the secret is encrypted with `APP_KEY`,
which the app still has). The copy refuses a target that already holds rows, and waits while
deployments are in flight. `drop-source` refuses the set Slipstream is still reading. It also
refuses until the new connection has caught up with the old one: in every table, the newest
row on the old set must also be on the new one. Old rows deleted by the nightly prune don't
trip that check.

Run these as the deploy user, so the SQLite file belongs to the user that writes it.
`slipstream:doctor` checks the file and its directory, because SQLite writes journal files
beside the database. Leave the rows in the app's `migrations` table that record Slipstream's
migrations, since they are how `artisan migrate` knows the schema is current. The creates are
guarded, so `migrate:fresh` on the app database leaves Slipstream's tables alone. If you
published Slipstream's migrations into `database/migrations`, publish them again with
`php artisan slipstream:publish --migrations --force`, because older copies ignore the
setting.

## PCI mode

For servers inside a PCI DSS cardholder data environment — a payment vault, a gateway
integration host — or any server where the dashboard must not exist. PCI mode reduces
Slipstream on that node to one thing: **receive a signed push from the git host, build a
release, swap.**

```bash
php artisan slipstream:install --pci     # writes SLIPSTREAM_PCI_MODE=true to the shared .env
```

Or put `SLIPSTREAM_PCI_MODE=true` in the shared `.env` yourself. It is env-driven on purpose:
with the dashboard, the `.env` editor and env sync all off, nothing inside Slipstream can flip
it back — only someone with shell access editing the file. (`install --pci` clears a cached
config and route table so the running app picks it up at once; a deploy's `optimize` rebuilds
both from the shared `.env` anyway.)

Behind a load balancer, or in an Auto Scaling group, add
[poll mode](#poll-mode-and-auto-scaling-groups): the node then registers no route at all and
deploys new releases by itself.

| | Normal | PCI mode |
|---|---|---|
| Dashboard (`/slipstream/*`) | Gated UI | Not registered: `404`. The Livewire components are not registered either, and `Slipstream::check()` is always false as a backstop |
| Dashboard assets | Published to `public/vendor/slipstream` | Not published by `install` |
| Shared `.env` editor | View / edit / push from the UI | Gone with the dashboard; Slipstream never writes the file again |
| Env sync | Encrypted snapshot of each node's `.env` in the shared DB, pushes applied by the heartbeat | Off: no snapshot is published, no push is applied, `/hooks/env-sync` does not exist |
| Relay fan-out, `/hooks/relay` | This node can fan a push out to peers and accept a relayed one | Off and not registered, whatever the `relay_enabled` setting says; a relay-signed delivery aimed at the deploy hook is refused and recorded |
| Fleet deploy queue, `/hooks/deploy-sync` | A dashboard deploy/rollback on another node queues a row this node's heartbeat claims | This node never claims a queued row; the endpoint does not exist |
| Deploy hooks | Editable in the dashboard | Still run — they are the build (composer, migrate, optimize); changeable only with database access |
| Webhook secret | Reveal / rotate in the dashboard | `slipstream:webhook-secret --reveal` / `--rotate`, from the shell |
| Audit log channel | Off unless `SLIPSTREAM_AUDIT_LOG=true` | On: every delivery outcome, deployment status change and action is also written to a log channel |

What keeps working: the provider webhook (`POST /slipstream/hooks/deploy`) with its HMAC/token
verification, per-commit idempotency, body cap and rate limit; the whole deploy (fetch, link
shared paths, composer, hooks, health check, swap, FPM reload, prune) and rollback;
`slipstream:deploy`, `slipstream:rollback`, `slipstream:healthcheck`, `slipstream:doctor`; the
minute heartbeat (server registry, stuck-deploy watchdog); notifications; the daily prune.

### Operating a PCI node

Every dashboard page has a command, so nothing needs the UI:

```bash
php artisan slipstream:status                        # the front page: servers, drift, what's running, what failed
php artisan slipstream:deployments --active          # or --failed, --status=building, --server=this
php artisan slipstream:deployment --follow           # tail the newest deploy on this node; exits 1 if it fails
php artisan slipstream:deliveries                    # the webhook delivery log
php artisan slipstream:hooks                         # list; show/add/edit/enable/disable/delete/move
php artisan slipstream:hooks add --step=composer --name="Build assets" --script-file=deploy/build.sh
php artisan slipstream:servers                       # list; pause/resume, set --run-migrations=0, forget
php artisan slipstream:pipelines                     # list; add --name=main --branch=main
php artisan slipstream:settings show                 # set --repo=… --branch=… --keep-releases=…
php artisan slipstream:webhook-secret --reveal       # the value to paste into the git host
php artisan slipstream:deploy                        # deploy the configured branch on this server now
php artisan slipstream:deploy --sha=<sha>            # pin a commit (the dashboard's Redeploy)
php artisan slipstream:rollback
php artisan slipstream:doctor
```

Each PCI node needs its own provider webhook — there is no relay, and `--fleet` is refused —
unless it runs in [poll mode](#poll-mode-and-auto-scaling-groups), which needs none. A fresh
`install` gives a new database its own webhook secret and fleet key, so a vault
with its own database shares no secret with the rest of your fleet. Every change made this
way is recorded in `slipstream_activity_log` with `via: cli` and, in PCI mode, in the audit
log channel.

Slipstream's own records — deployments and their logs, heartbeats, webhook deliveries — go to
the app's default database unless `SLIPSTREAM_DB_CONNECTION` says otherwise, and on a vault
that is usually the CDE database. They are deployment metadata, but there is no need for them
to sit there: see [Slipstream's own database](#slipstreams-own-database).

### Restricting the hook's source

The signature is the control that matters; an allow-list is the second one an assessor asks
for on an unauthenticated route:

```dotenv
SLIPSTREAM_WEBHOOK_ALLOWED_IPS=<ips or CIDRs, comma-separated>
```

A delivery from any other address is refused with `403` before its body is read and recorded
in the delivery log naming the address seen. GitHub publishes its hook ranges under `hooks` at
`https://api.github.com/meta` and says they change over time; Bitbucket Cloud's are at
`https://ip-ranges.atlassian.com/`; GitLab.com's are in its documentation. Refresh the list
when a delivery is rejected. Behind a proxy or load balancer the app must trust it (Laravel's
`TrustProxies`), or the address Slipstream sees is the proxy's and every delivery is refused —
the rejected row tells you which address it saw. An `allow`/`deny` block on the hook
`location` in nginx does the same job (`stubs/nginx-slipstream.conf` has one); in PCI mode
`slipstream:doctor` warns when the in-app list is empty, which is expected if nginx does it.

### Audit log

In PCI mode every webhook delivery outcome, every deployment status change and every action
(what `slipstream_activity_log` records) is also written as a structured `slipstream.<event>`
line to a Laravel log channel — `SLIPSTREAM_AUDIT_LOG_CHANNEL`, or the app's default channel.
Point it at whatever ships logs off the box (syslog, CloudWatch, your SIEM): PCI DSS
requirements 10.3.2 and 10.3.3 want audit records protected from modification and promptly
copied to a central log server, and a table the deploy user can `UPDATE` is not that.
`SLIPSTREAM_AUDIT_LOG=true` turns the same lines on outside PCI mode. Lines never carry hook
output, `.env` contents or settings values.

### Doctor checks

`slipstream:doctor` reports the mode first and adds these checks around it:

- **PCI mode** — on/off; warns when `relay_enabled` is set in the settings table (ignored,
  but the configuration should read like the behaviour).
- **deploy trigger** — warns in PCI mode on the webhook trigger: with no relay, a webhook
  behind a load balancer reaches one node only. `SLIPSTREAM_TRIGGER=poll` is the fix.
- **slipstream database** — warns in PCI mode while Slipstream's records are on the app's
  database (in every mode it fails a connection that is undefined, unreachable or missing the
  tables, and a SQLite file the pool user cannot write).
- **webhook source** — warns in PCI mode when `SLIPSTREAM_WEBHOOK_ALLOWED_IPS` is empty
  (skipped in poll mode, which has no hook).
- **PCI sentinel keys** — `pci.sentinel_keys` (config, so committed and shared by every
  server) names `.env` keys only a CDE server holds, e.g. the vault database host or a gateway
  credential ARN. A server that has one of them with PCI mode off **fails** the check: that is
  the vault's credentials on a node that still has the dashboard, the `.env` editor and env sync.

### What PCI mode is not

It does not take the server out of scope. A deploy tool that can change the code running in
the CDE is a connected-to / security-impacting system under the PCI SSC's scoping guidance
whether or not the dashboard exists; what PCI mode does is reduce it to the functions the
server needs (requirement 2.2.4) and make the rest easy to show. Network segmentation
(requirement 1), MFA for everyone who can reach the host (8.4.2), change control over what
gets pushed to the deployed branch (6.5.1), and where the vault's own secrets live (a secrets
manager rather than the `.env`) are the host's and the infrastructure's job. Deploy logs still
hold raw hook output (`deploy/logs/*.log` at `0600`, and `slipstream_deployment_events`):
nothing redacts it.

## Poll mode and Auto Scaling groups

A webhook reaches one address. In normal mode relay carries a push on to the peers; in PCI
mode nothing does; and behind a load balancer, in an Auto Scaling group whose instances come
and go, the git host cannot address the nodes at all. Poll mode turns the trigger around:
each node watches the deploy ref from its minute heartbeat and converges on it by itself.
Nothing reaches the node from outside.

```dotenv
SLIPSTREAM_TRIGGER=poll
SLIPSTREAM_DEPLOY_REF=refs/heads/production       # or v*: the highest version tag
SLIPSTREAM_REPOSITORY=git@github.com:org/app.git
SLIPSTREAM_MIGRATE_COMMAND="migrate --force"
SLIPSTREAM_MIGRATION_LOCK_STORE=slipstream         # a shared cache store of its own, see below
```

In poll mode the provider webhook (`/hooks/deploy`) and `/hooks/relay` are not registered, no
webhook secret is generated, and `doctor` stops asking where the hook accepts deliveries from.
With PCI mode on as well, Slipstream registers no route at all. Poll mode works the same with
PCI mode off: app nodes in an Auto Scaling group have the same new-instance problem a vault
does.

### Settings come from the environment

Everything a node needs has to come from the release or the shared `.env`, never only from
its own database: an instance that boots with an empty one must deploy exactly like a node
that has run for a year. So in poll mode the branch, the release count, the shared paths and
whether a node runs migrations come from config and the shared `.env`, even where the
dashboard or `slipstream:servers set` stored a value; the dashboard shows those fields
read-only. (Webhook mode keeps preferring stored values, as before.)

| Variable | Default | |
|---|---|---|
| `SLIPSTREAM_TRIGGER` | `webhook` | `poll` turns poll mode on |
| `SLIPSTREAM_DEPLOY_REF` | the deploy branch (`SLIPSTREAM_BRANCH`) | A branch (`production` or `refs/heads/production`); one tag (`refs/tags/v1.4.2`), which holds the fleet on that release; or a tag pattern (`v*` or `refs/tags/v*`): the highest version among the matching tags |
| `SLIPSTREAM_DEPLOY_PRERELEASES` | `false` | Let a tag pattern pick a pre-release such as `v2.0.0-rc.1` |
| `SLIPSTREAM_REPOSITORY` | the stored setting | The repository a fresh node mirrors. Wins over the stored setting in either mode |
| `SLIPSTREAM_POLL_INTERVAL` | `60` | Seconds between checks. The heartbeat runs once a minute, so less than 60 behaves as 60 |
| `SLIPSTREAM_POLL_JITTER` | `20` | Each check first waits a random 0 to N seconds (at most 45), so a fleet doesn't reach the git host in the same second |
| `SLIPSTREAM_POLL_RETRY_MINUTES`, `SLIPSTREAM_POLL_RETRY_MAX_MINUTES` | `5`, `240` | Back-off for a commit whose deploy failed on this node |
| `SLIPSTREAM_MIGRATE_COMMAND` | unset | The [migrate step](#migrations-once-per-commit), e.g. `migrate --force` |
| `SLIPSTREAM_RUN_MIGRATIONS` | `true` in poll mode; the server registry in webhook mode | `false` keeps a node from running the migrate step |
| `SLIPSTREAM_MIGRATE_WAIT` | the lock TTL plus 60 | Seconds a node waits for another node's run of the same commit |
| `SLIPSTREAM_PAUSED` | `false` | Hold every node that reads this `.env`: no deploy starts |
| `SLIPSTREAM_KEEP_RELEASES` | `5` | Releases kept on each node |
| `SLIPSTREAM_HOOKS_FILE` | `slipstream-hooks.php` | The [hooks file](#hooks-from-the-repository), relative to the release root |
| `SLIPSTREAM_NOTIFY_MAIL`, `SLIPSTREAM_NOTIFY_SLACK_URL`, `SLIPSTREAM_NOTIFY_DISCORD_URL`, `SLIPSTREAM_NOTIFY_WEBHOOK_URL`, `SLIPSTREAM_NOTIFY_ON_SUCCESS`, `SLIPSTREAM_NOTIFY_ON_FAILURE` | the stored settings | Notification targets. Each wins over the stored setting in either mode |
| `SLIPSTREAM_FORGET_OFFLINE_AFTER_HOURS` | `0` (never) | The daily prune forgets servers silent this long, so terminated instances don't pile up in a shared registry |

Shared paths have no variable: they come from `deploy.shared_paths` in your published
`config/slipstream.php`, which the repository carries.

Keep `SLIPSTREAM_SERVER` out of a shared `.env`, or every instance claims the same identity.
Unset, a poll-mode node names itself after its EC2 instance ID, read from `/sys` on Nitro
instances or from IMDSv2 on Xen ones; off EC2 it uses its hostname plus the first eight
characters of `/etc/machine-id`. EC2 derives hostnames from private IPs, which the next
instance may get, but an instance ID is never reused, so a new instance never inherits an old
one's pause, pin or scheduler lock. (A webhook-mode node keeps its hostname.) If several nodes
share one machine, give each its own `SLIPSTREAM_SERVER`.

### How releases are cut

Releasing is a deliberate act in git: a merge to the release branch, or a pushed tag. With a
tag pattern, nodes deploy the highest version among the matching tags (`v1.10.0` beats
`v1.9.3`). Tags that aren't versions are ignored, pre-releases are skipped unless
`SLIPSTREAM_DEPLOY_PRERELEASES=true`, and an annotated tag deploys the commit it points at.

Protect whatever the ref names, because anyone who can move it deploys to every node. Use a
branch ruleset on the release branch (pull requests with review, no force-push, no deletion),
or a tag ruleset that limits who may create, move or delete the release tags. The reviewed
merge or the tag is then the change-control record PCI DSS requirement 6.5.1 asks for.

### What a node does each minute

The scheduler's minute heartbeat (`slipstream:heartbeat`, run as the PHP-FPM pool user) runs
the check when one is due:

1. It waits a random 0 to `SLIPSTREAM_POLL_JITTER` seconds.
2. It asks the git host where the deploy ref points: `git ls-remote` against the mirror's
   origin, with the deploy key the node already fetches with.
3. **up-to-date:** `current` already runs that commit.
4. **held:** `SLIPSTREAM_PAUSED` is on, or this node is paused, disabled or
   [pinned](#rollback-in-poll-mode). Checks go on; no deploy starts.
5. **busy:** a deploy is pending or building on this node.
6. **backoff:** that commit's deploy failed here. It is tried again after 5 minutes, then
   10, 20 and so on up to 4 hours. Only failures since this node's last successful deploy
   count, and a newer commit is tried at once. `php artisan slipstream:deploy --sha=<sha>`
   forces it.
7. Otherwise it starts the normal deploy of that commit, detached: fetch, shared links,
   composer, migrate, health check, swap, FPM reload, prune.

`slipstream:status` shows the last check, and the audit log records each change of result as
`slipstream.poll.<result>`, and each deploy a check starts as `slipstream.poll.deploy_started`.
The heartbeat runs in the background under a mutex of the node's own, so the wait never holds
up the app's own scheduled tasks, and a cache store the fleet shares never makes one node skip
another's heartbeat. If a heartbeat dies mid-run (a reboot, a terminated instance), its mutex
expires after five minutes.

### Hooks from the repository

Hooks added with `slipstream:hooks add` or in the dashboard live in the node's database, and a
fresh instance's database is empty. Put them in a file the repository carries instead,
`slipstream-hooks.php` at its root. Each release's hooks are read from that release right after
the fetch, so a hook ships with the commit that needs it:

```php
<?php

// slipstream-hooks.php
return [
    [
        'name' => 'Build assets',
        'step' => 'composer',
        'script' => '{{ npm }} ci --no-audit --no-fund && {{ npm }} run build && rm -rf node_modules',
    ],
    ['name' => 'Optimize', 'step' => 'composer', 'script' => '{{ php }} artisan optimize'],
    ['name' => 'Link storage', 'step' => 'composer', 'script' => '{{ php }} artisan storage:link || true'],
    ['name' => 'Restart Horizon', 'step' => 'fpm', 'script' => '{{ php }} artisan horizon:terminate || true'],
];
```

Each hook takes `name`, `step` and `script` (required); `position` (`before` or `after`, default
`after`); `servers` (a list of server names, default every node); `fleet_lock` (one node per
commit runs it); and `enabled`. Steps run in this order: `fetch`, `link_shared`, `composer`,
`migrate`, `health`, `swap`, `fpm`, `prune`. Scripts take the same placeholders as stored hooks:
`{{ php }}`, `{{ composer }}`, `{{ npm }}`, `{{ release }}`, `{{ sha }}`, `{{ branch }}`,
`{{ app_root }}`.

When the file exists it replaces the default hooks Slipstream seeds (build assets, run
migrations, optimize, link storage, restart Horizon and Reverb), so list the ones you need, as
above. A default you edited is your own hook now and still runs, and so do the other hooks
stored in the database: after the file's, in the same step. A file that fails to load, or names
an unknown step, fails the deploy rather than building without its hooks. A hook that runs
before the fetch can't come from the release it would run before, so keep that one in the
database. `slipstream:hooks` lists both kinds and says where each comes from. The file works
in webhook mode too.

### Migrations once per commit

```dotenv
SLIPSTREAM_MIGRATE_COMMAND="migrate --force"       # any artisan command, e.g. vault:migrate --force --if-vault
SLIPSTREAM_MIGRATION_LOCK_STORE=slipstream
```

With a command set, every deploy runs a migrate step after composer and before the health
check, and exactly one node runs the command for a given commit: the first to take the lock in
the shared store. The others wait for that run (up to `SLIPSTREAM_MIGRATE_WAIT`, by default the
lock TTL plus a minute), then go on to the health check and the swap, or fail their deploy if
the run failed. A node that deploys the commit later skips the command. If the node holding the
lock dies, its lock expires and a waiting node takes the run over. A node with
`SLIPSTREAM_RUN_MIGRATIONS=false` never runs the command, but it still waits for a run in
progress and fails its deploy when the last run failed.

The lock is held for at least `SLIPSTREAM_MIGRATE_TIMEOUT` plus two minutes, whatever
`SLIPSTREAM_MIGRATION_LOCK_TTL` says. A lock that lapsed while the command still ran would hand
the same migration to a second node. `doctor` warns when the TTL is set lower than that.

With a command set, the seeded "Run migrations" hook stops running (unless you edited it,
which makes it your own), and `install` and `bootstrap` stop seeding it. Leave the command
unset and nothing changes: that hook runs as it always has.

The record of which commits are migrated lives in the shared store, scoped to the command and
to the app's database. A fleet running another command, or a staging environment sharing the
cache server, never acts on it. It is a signal, not the guarantee: if it is lost to eviction
or a flush, the next node runs the command again, so the command must be safe to repeat.
`artisan migrate` is safe, because it only runs what is pending.

Give the store a cache of its own that every node shares and nothing flushes: a deploy hook
running `cache:clear`, or ordinary eviction in the app's cache, would erase the locks and the
record. For example, a `slipstream` store in `config/cache.php` on a Redis database of its own:

```php
'slipstream' => [
    'driver' => 'redis',
    'connection' => 'slipstream',        // a connection in config/database.php's redis block
    'lock_connection' => 'slipstream',
],
```

When Slipstream keeps its records in a per-node database (`SLIPSTREAM_DB_CONNECTION`), each node
also brings that database's schema current at bootstrap, in every deploy's migrate step, and
from the minute heartbeat. The app's shared `migrations` table can't do it, because it records
a Slipstream migration as run the first time any node runs it.

### Bootstrapping a new instance

A node has no `artisan` until its first release exists. `stubs/slipstream-bootstrap.sh` solves
that: it makes a throwaway checkout of the deploy ref, installs its dependencies, runs
`php artisan slipstream:bootstrap` from it, and removes it. The checkout is the commit the ref
names, read from the shared `.env`: the branch, the tag, or the highest version tag matching
the pattern, by the same rules as the deploy. So the only code that runs before the first
release (composer's scripts and artisan, with the production `.env`) is released code.
Bootstrap then:

1. creates Slipstream's tables if they are missing, and brings them current;
2. registers the server, unheld: a pause or pin already on its row came with the name, and is
   cleared;
3. mirrors `SLIPSTREAM_REPOSITORY` if the node has no mirror yet;
4. deploys the deploy ref's commit, blocking: a normal deploy, with its hooks, migrate step
   and health check;
5. runs the health check again on what is live;

and exits non-zero unless the node can serve. It is idempotent: on a node already serving the
ref's commit it only re-checks. The real release's composer install mostly comes from
composer's cache.

The image holds the OS, PHP, nginx, supervisor, a copy of the stub, and the deploy user's
`~/.ssh/known_hosts` with your git host's published host keys. The deploy key and the shared
`.env` come from a secrets manager at boot. A launch template's user data, for example:

```sh
#!/bin/sh
# EC2 user data: runs as root on first boot.
set -eu
APP_ROOT=/var/www/app
REPO=git@github.com:org/app.git

# Secrets at boot, never in the image.
install -d -o deploy -g deploy -m 0755 "$APP_ROOT"       # nginx traverses it to current/public
install -d -o deploy -g deploy -m 0700 /home/deploy/.ssh
umask 077
aws secretsmanager get-secret-value --secret-id app/shared-env \
    --query SecretString --output text > "$APP_ROOT/.env"
aws secretsmanager get-secret-value --secret-id app/deploy-key \
    --query SecretString --output text > /home/deploy/.ssh/id_ed25519   # ssh's default key
chown deploy:deploy "$APP_ROOT/.env" /home/deploy/.ssh/id_ed25519
umask 022

# Tables, registry, mirror, the deploy ref deployed and health-checked. As the
# pool user, so everything it creates belongs to the user that deploys later.
sudo -u deploy -H APP_ROOT="$APP_ROOT" REPO="$REPO" sh /opt/slipstream/slipstream-bootstrap.sh

# The scheduler, as the pool user: its minute heartbeat is what polls.
echo "* * * * * deploy cd $APP_ROOT/current && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/app

# Only now may the instance take traffic.
TOKEN=$(curl -sX PUT http://169.254.169.254/latest/api/token -H 'X-aws-ec2-metadata-token-ttl-seconds: 60')
ID=$(curl -s -H "X-aws-ec2-metadata-token: $TOKEN" http://169.254.169.254/latest/meta-data/instance-id)
aws autoscaling complete-lifecycle-action --auto-scaling-group-name app \
    --lifecycle-hook-name launch --instance-id "$ID" --lifecycle-action-result CONTINUE
```

Give the group a launch lifecycle hook whose default result is `ABANDON`. An instance whose
bootstrap fails then never completes the action; it is terminated when the hook times out,
and the group launches another. Besides `APP_ROOT` and `REPO`, the stub takes `PHP`,
`COMPOSER`, `BOOTSTRAP_BRANCH` (a branch or tag to check out instead of the deploy ref) and
`BOOTSTRAP_ARGS`. Options for `slipstream:bootstrap` go in `BOOTSTRAP_ARGS` or after the
script name: `sh slipstream-bootstrap.sh --no-fallback`.

**When the ref's commit fails.** A broken release would otherwise stop the group from scaling
out: each new instance would fail its health check and be replaced, over and over. So when the
deploy ref's own commit fails to deploy, bootstrap deploys the fleet's last good commit
instead: the last commit any node deployed successfully from the ref, kept in the shared store.
It does so only if the ref still contains that commit. For a branch, the commit must be in its
history; for a tag pattern, a matching tag must point at it now; an exact tag has nothing else
to offer. So a release rolled back by deleting its tag never comes back this way, and neither
does a commit someone wrote into the store. A fallback, or a refused one, is reported on
bootstrap's output and in the audit log (`slipstream.activity.bootstrap.fell_back`,
`slipstream.activity.bootstrap.fallback_refused`), and the node keeps retrying the ref, with
back-off, like any other. Alarm on those lines. Pass
`--no-fallback` to fail instead.

### Rollback in poll mode

On one node, `php artisan slipstream:rollback` repoints `current` at the previous release and
**pins** the node there. Without the pin, the next check would redeploy the commit you just
stepped back from. Checks go on, but no deploy starts until someone resumes the node. Any
deploy that leaves the ref pins the same way, such as `slipstream:deploy --sha=<another
commit>` or a dashboard deploy of another commit. Deploying the ref's own commit (a forced
retry, say) doesn't pin. `slipstream:status` shows the pin and how to lift it:

```bash
php artisan slipstream:servers resume      # unpin; the next check converges on the deploy ref
```

To roll back the whole fleet, move the ref. Revert the bad commit on the release branch; with
tags, push a new version tag on the last good commit, or delete the bad tag. Every node
converges on its next check. The move is a reviewed change like any release, so it leaves the
same change-control record. As always, rollback is code only: it does not un-migrate the
database.

### Seeing the fleet

- `slipstream:status` on a node shows the trigger and the deploy ref, the commit running, the
  last check (when, its result and detail), the commit the ref pointed at then, and any hold
  or back-off with what to run to clear it.
- The audit log channel (on in PCI mode; `SLIPSTREAM_AUDIT_LOG=true` elsewhere) receives the
  checks that start a deploy, every change in a check's result, every deployment status
  change, migrations run, waited for or failed, every pin and resume, and bootstrap's outcome.
  Ship it to CloudWatch or your SIEM for a fleet view that needs no shared database.
- Every release carries a `RELEASE_SHA` file at its root, so the app's own health route can
  report the commit it serves to a load balancer or a dashboard. `slipstream:healthcheck`
  prints it too.

### Doctor in poll mode

`slipstream:doctor` checks that the deploy user's key can `ls-remote` the ref (and shows what it
resolves to), warns when `SLIPSTREAM_DEPLOY_REF` is unset or no heartbeat ran in the last three
minutes, and confirms the webhook route is absent. With a migrate command set, it checks that
the lock store can be shared at all, and that `SLIPSTREAM_MIGRATION_LOCK_TTL` outlasts
`SLIPSTREAM_MIGRATE_TIMEOUT`. A PCI node on the webhook trigger gets a warning that each node
needs its own webhook, and a pointer to poll mode.

### Poll mode in a PCI environment

- Poll mode adds no inbound endpoint. It does add steady outbound traffic from every node to
  the git host: the `ls-remote` each check, and the fetch when the ref moves. Allow that in your
  egress rules, and nothing else.
- The shared lock store coordinates deploys into the CDE, which puts it in scope. Give it its
  own network path and credentials.
- Fetch the shared `.env` and the deploy key from a secrets manager at boot; never bake them
  into the image. Every copy of an image is a copy of both, and if `APP_KEY` encrypts
  cardholder data it is a key under requirement 3.
- Run the scheduler as the PHP-FPM pool user, like every deploy, and keep `SLIPSTREAM_SERVER`
  out of the shared `.env`.

### Not in this release

Rollout caps (a limit on how many nodes build or swap at once), signed-release verification,
and an artifact source (a tarball CI built once, named by an S3 object or an SSM parameter,
in place of git) are not part of 0.5. Releases are found through an interface,
`Slipstream\Release\ReleaseSource`, so a source other than git can be bound later; bootstrap
falls back only with a source that also implements `Slipstream\Release\ReleaseHistory`.

## Upgrading

### To 0.5.1

- **Poll-mode nodes rename themselves.** With `SLIPSTREAM_SERVER` unset, a poll-mode node is now
  named after its EC2 instance ID (or its hostname plus machine ID), not its hostname. After the
  upgrade each node registers under its new name. Its history stays under the old one, which
  stops reporting: forget it with `slipstream:servers forget <old-name>`, or let
  `SLIPSTREAM_FORGET_OFFLINE_AFTER_HOURS` do it. A pin set on the old name does not carry over.
  Set `SLIPSTREAM_SERVER` to keep a name. Upgrade between releases, so no deploy is in flight
  while the name changes. Webhook-mode nodes keep their hostnames.
- **Copy the new `slipstream-bootstrap.sh` into your image.** Its checkout is now the deploy
  ref, not the repository's default branch, and `BOOTSTRAP_BRANCH` only overrides that. Options
  pass through to `slipstream:bootstrap`.
- **Bootstrap's fallback is stricter.** It deploys the fleet's last good commit only when the
  deploy ref still contains it. A release source of your own must also implement
  `Slipstream\Release\ReleaseHistory` for bootstrap to fall back at all.
- **Locks last as long as what they guard.** The migrate step's lock is held for at least
  `SLIPSTREAM_MIGRATE_TIMEOUT` plus two minutes, and a fleet-locked hook's for at least an hour
  and two minutes, whatever `SLIPSTREAM_MIGRATION_LOCK_TTL` says. If a node dies holding one,
  the lock outlives it by that much: a waiting node takes the migration over later, and other
  nodes skip the fleet-locked hook for longer.
- The heartbeat's scheduler mutex now expires after five minutes rather than a day, and prune's
  after an hour.

### To 0.5.0

- Require `slipstream/slipstream:^0.5`. A `^0.4` constraint stays on 0.4.x.
- The new migration adds the poll and pin columns to `slipstream_servers`; deploys run it with
  the rest (`artisan migrate`). Like every Slipstream migration it is guarded, so it is safe to
  run twice. Nodes that keep Slipstream's records in a database of their own
  (`SLIPSTREAM_DB_CONNECTION`) bring it current themselves from the minute heartbeat;
  `php artisan slipstream:database ensure` does it by hand.
- Webhook mode is unchanged unless you set one of the new variables. `SLIPSTREAM_REPOSITORY` and
  the `SLIPSTREAM_NOTIFY_*` variables win over the stored settings in either mode, but only when
  set.
- The migrate step and the hooks file are opt-in. Without `SLIPSTREAM_MIGRATE_COMMAND` the
  "Run migrations" hook runs as before, and without a `slipstream-hooks.php` in the release the
  stored hooks run as before.
- A deploy runs with the Slipstream code of the release it deploys *from*. So the deploy that
  installs 0.5.0 still runs as 0.4 did, and the migrate step and the hooks file take effect
  from the deploy after it. Poll mode starts once 0.5.0 is live.
- The lock for hooks marked `fleet_lock` is now named after the hook and the app's database,
  instead of the hook's row id. Nodes still a release behind won't see the new lock, so let the
  upgrade reach every node before the next deploy.
- Slipstream's scheduled heartbeat and prune now hold a mutex per node, and the heartbeat runs
  in the background. On a fleet sharing its default cache store, nodes no longer skip each
  other's heartbeats.

**Moving a fleet to poll mode.** Deploy 0.5.0 the usual way first. Then set
`SLIPSTREAM_TRIGGER=poll` and `SLIPSTREAM_DEPLOY_REF` in the shared `.env`, and run
`php artisan optimize` in `current/` and reload PHP-FPM, or deploy once more. Then remove the
provider webhook at the git host, since its URL now returns `404`. If nodes opted out of
migrations with `slipstream:servers set --run-migrations=0`, poll mode no longer reads that
value: `SLIPSTREAM_RUN_MIGRATIONS=false` in the shared `.env` opts out every node that reads
it. For a vault that ran its own migrations from a stored hook, set
`SLIPSTREAM_MIGRATE_COMMAND="vault:migrate --force --if-vault"` instead, and delete the stored
hook once a deploy has run the migrate step.

## Preview the dashboard locally (no host app)

You can view the full dashboard without installing Slipstream into a project — it
boots the package in a throwaway app via `orchestra/testbench` and seeds sample data:

```bash
composer install
composer preview:build   # publish assets + fresh migrate + seed sample data
composer preview         # serve at http://127.0.0.1:8000/slipstream
```

The gate is open automatically (the preview app runs in the `local` environment). The
harness files — `testbench.yaml`, `preview/`, and `database/preview.sqlite` — are dev-only
and never part of the installed package.

## Contributing / tests

```bash
composer test
```

### Dashboard styling

The dashboard ships a **pre-compiled** `public/app.css` — host apps consume it as-is
(via `slipstream:publish --assets`) and never need Node or a build step. The stylesheet
is built with Tailwind CSS v4 at the **package** level; only maintainers editing the UI
run it:

```bash
npm install
npm run build        # resources/css/app.css -> public/app.css (commit the result)
npm run dev          # watch mode while iterating
```

`package.json` overrides `@parcel/watcher` to 2.6+: the Tailwind CLI pins 2.5.1, whose glob
dependency (`braces`) has an unfixed denial-of-service advisory, and 2.6 drops it. Only
`npm run dev` loads the watcher — builds never do. Its install script is denied in
`allowScripts` because it only builds from source on request; the prebuilt binary comes from
the platform package. Check both toolchains with `composer audit` and `npm audit`.

## License

MIT
