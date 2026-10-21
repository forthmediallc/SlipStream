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
- `ext-sodium` and `ext-zlib` on nodes that deploy [artifact releases](#artifact-releases-build-once-in-ci)

## Installation

```bash
composer require slipstream/slipstream
php artisan slipstream:install
```

For a server inside a PCI cardholder data environment, or anywhere the dashboard must not
exist, install with `--pci` instead — see [PCI mode](#pci-mode). For an Auto Scaling group,
or any fleet a webhook can't reach node by node, see
[Poll mode and Auto Scaling groups](#poll-mode-and-auto-scaling-groups). To keep git,
composer and npm off the servers altogether — CI builds and signs each release once, nodes
only verify and unpack — see [Artifact releases](#artifact-releases-build-once-in-ci).

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
| `hooks_path` | Where the hook endpoints are served; null = `{path}/hooks` (`SLIPSTREAM_HOOKS_PATH`). See [Behind an SSO proxy](#behind-an-sso-proxy) |
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
| `webhook.allowed_ips` | Sources the provider hook accepts deliveries from: IPs, CIDRs and the keywords `github` / `bitbucket`; empty = any (`SLIPSTREAM_WEBHOOK_ALLOWED_IPS`). See [Restricting the hook's source](#restricting-the-hooks-source) |
| `webhook.ranges_refresh_hours` / `webhook.ranges_stale_hours` | How often a keyword's published ranges are downloaded (default 6) and when doctor calls them old (default 48) (`SLIPSTREAM_WEBHOOK_RANGES_REFRESH_HOURS`, `…_STALE_HOURS`) |
| `audit.enabled` / `audit.channel` | Structured audit lines to a log channel — on in PCI mode by default (`SLIPSTREAM_AUDIT_LOG`, `SLIPSTREAM_AUDIT_LOG_CHANNEL`) |
| `trigger.*` | `webhook` (default) or `poll`, the deploy ref, the repository, poll interval, jitter and back-off (`SLIPSTREAM_TRIGGER`, `SLIPSTREAM_DEPLOY_REF`, …). See [Poll mode](#poll-mode-and-auto-scaling-groups) |
| `release.source` | `git` (default) or `artifact`: releases CI built and signed, read from a filesystem disk (`SLIPSTREAM_RELEASE_SOURCE`). See [Artifact releases](#artifact-releases-build-once-in-ci) |
| `release.environment` | The environment releases are signed for, bound into every signature; required in artifact mode (`SLIPSTREAM_RELEASE_ENVIRONMENT`) |
| `artifacts.*` | The store (`SLIPSTREAM_ARTIFACTS_DISK`, `SLIPSTREAM_ARTIFACTS_PREFIX`), the public keys nodes verify with (`SLIPSTREAM_RELEASE_PUBLIC_KEYS`), packaging excludes and the paths allowed to carry key material (`exclude`, `allow`), download limits (`SLIPSTREAM_ARTIFACTS_MAX_*`), the weekly verify (`SLIPSTREAM_ARTIFACTS_VERIFY_WEEKLY`) and, in CI only, the signing key (`SLIPSTREAM_SIGNING_KEY`) |
| `deploy.hook_env` / `deploy.hook_env_pass` | What hooks see in their environment: `inherit` (everything, the default in git mode) or `minimal` (PATH, HOME, the locale, proxy and CA variables and `SLIPSTREAM_*`; the default in artifact mode), plus the names the pass list adds (`SLIPSTREAM_HOOK_ENV`, `SLIPSTREAM_HOOK_ENV_PASS`). The detached deploy always inherits |
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
keeps serving the previous release after a swap. The stub hands only `index.php` to PHP-FPM
and answers any other `.php` with a 404, as Laravel's own nginx configuration does: a `.php`
file that lands in `storage/app/public` through an upload must never run as `/storage/x.php`.

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

- **URL:** `POST https://your-app/slipstream/hooks/deploy` (`SLIPSTREAM_PATH` sets the prefix;
  `SLIPSTREAM_HOOKS_PATH` moves the hook endpoints elsewhere, see below)
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

### Behind an SSO proxy

An SSO proxy in front of the dashboard (Cloudflare Access, say) must not stand in front of the
git host's deliveries, or GitHub gets a login page instead of the hook. Either exempt every hook
endpoint — all of `/slipstream/hooks/*`: `deploy`, and `relay`, `env-sync` and `deploy-sync`,
which carry your own nodes' traffic — or move them out from under the dashboard prefix:

```dotenv
SLIPSTREAM_HOOKS_PATH=_hooks        # the hooks answer at /_hooks/deploy, /_hooks/relay, …
```

Then protect `/slipstream/*` as a whole. Every node of a fleet must share the value, because
peers address each other's relay and nudge endpoints with it, and like `SLIPSTREAM_PATH` it is
baked into a cached route table (`route:clear`, or a deploy). Behind Cloudflare, the source
allow-list only sees GitHub's address when `TrustProxies` trusts Cloudflare's published ranges
and nothing else: with `*` anyone can forge `X-Forwarded-For` past the list.

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
| `slipstream:webhook-ranges` | The git hosts' hook ranges behind the `github` / `bitbucket` allow-list keywords: `show`, `refresh [keyword]` |
| `slipstream:database` | Where Slipstream's records live: `status`, `copy --to=<connection>`, `drop-source` — moves them off the app database; `ensure` brings a node's own Slipstream database up to this version's schema |
| `slipstream:provision` | Deploy key, bare mirror, directory layout; `--adopt` converts a live checkout into the first release. In artifact mode it does not look for git, composer or node |
| `slipstream:bootstrap` | Bring a new instance up: tables, registry, mirror (git mode), the deploy ref deployed and health-checked; exits non-zero unless the node can serve. See [Bootstrapping a new instance](#bootstrapping-a-new-instance) |
| `slipstream:doctor` | Diagnose layout, ownership, PHP binary, sudo, git access, webhook reachability and source, hook scripts, the PCI-mode checks and, in artifact mode, the source, the pointer, the live release and where hooks come from — with the fix for each |
| `slipstream:deploy` | Run a deploy here (or `--deployment=`); `--sha=` pins a commit, `--tag=` deploys a tag, `--skip-hooks=1,2` skips hooks once, `--fleet` is the dashboard's fleet-wide Deploy. In poll mode, with nothing named, it deploys the deploy ref's commit now |
| `slipstream:rollback` | Point `current` at the previous release; `--fleet` rolls every eligible server back to its own previous release. In poll mode it also pins the node; in artifact mode it re-verifies the previous release first |
| `slipstream:artifact` | The CI side of [artifact releases](#artifact-releases-build-once-in-ci): `package` a checkout into `dist/`, `sign` it, `publish` it (or `--rollback --sha=` to repoint), `list` what the store holds, `keygen` a local key pair. `sign` and `publish` take the commit and ref from `--sha` and `--ref` (defaults `GITHUB_SHA`, `GITHUB_REF`) and `--generated`, `--allow`, `--keys` |
| `slipstream:verify` | Compare a release (default: the live one) with its signed manifest and its recorded cache hashes; exit 1 on drift (in artifact mode a missing manifest is drift), 2 with nothing to compare in git mode (`--release=`, `--json`, `--audit`). Scheduled weekly in artifact mode; the standalone tool's `verify-tree` is the check to run from outside the release |
| `slipstream:healthcheck` | Pre-swap checks (DB, Redis, custom); prints the release it ran in |
| `slipstream:heartbeat` | Refresh this node in the fleet registry, publish its `.env` snapshot, apply any queued env push, fail deploys orphaned by a killed process, download due provider hook ranges; in poll mode, check the deploy ref and start a deploy when it moved (scheduled every minute) |
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
- `SLIPSTREAM_HOOKS_PATH`, when set, must be identical on every node: peers append it to each
  other's Base URL. The provider hook ranges behind an allow-list keyword are downloaded once
  per shared database (any node's heartbeat serves the fleet); a PCI node with a database of
  its own downloads its own.
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
$middleware->validateCsrfTokens(except: ['slipstream/hooks/*']);   // or '<SLIPSTREAM_HOOKS_PATH>/*'
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

**A hook's command failed but the deploy succeeded.** A hook script runs through `sh -c`, which
reports only its *last* line's status: in a script of several lines a failed `npm run build`
followed by `rm -rf node_modules` passes. Put `set -e` on the first line, or join the lines with
`&&`. Since 0.5.2 the seeded defaults do, hooks from the release's hooks file get it
automatically (unless they set `'fail_fast' => false`), and `slipstream:doctor` names stored hooks
that still lack it.

**Every delivery is refused with `source not allowed`.** The allow-list rejected the address it
saw; `slipstream:deliveries` shows which. Behind a proxy or load balancer that is the proxy's
address until `TrustProxies` trusts it. With a provider keyword (`github`, `bitbucket`) in the
list, a keyword whose ranges were never downloaded matches nothing: `slipstream:webhook-ranges
show` says so, `slipstream:webhook-ranges refresh` downloads them now, and the minute heartbeat
keeps them current.

**GitHub gets a redirect or an HTML login page from the hook.** An SSO proxy in front of the
dashboard is in front of the hook too. Exempt `/slipstream/hooks/*` there, or move the hooks out
from under the dashboard with `SLIPSTREAM_HOOKS_PATH` — see [Behind an SSO proxy](#behind-an-sso-proxy).

## Security notes

- The hook endpoints (`/hooks/deploy`, `/hooks/relay`, `/hooks/env-sync`, `/hooks/deploy-sync`,
  under `SLIPSTREAM_HOOKS_PATH` when that is set) are the only unauthenticated routes: HMAC/token verified, CSRF-exempt (so provider POSTs work
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
SLIPSTREAM_WEBHOOK_ALLOWED_IPS=github,bitbucket,10.0.0.0/8     # keywords, IPs and CIDRs
```

A delivery from any other address is refused with `403` before its body is read and recorded
in the delivery log naming the address seen. The keywords stand for the provider's published
hook ranges, which change over time: GitHub's under `hooks` at `https://api.github.com/meta`,
Bitbucket Cloud's at `https://ip-ranges.atlassian.com/` (its egress ranges). The minute
heartbeat downloads them every `SLIPSTREAM_WEBHOOK_RANGES_REFRESH_HOURS` (default 6) with an
ETag, so an unchanged list costs no rate limit, and keeps the last good list when a download
fails. A keyword never downloaded matches nothing, so every delivery is refused until the first
download: run `php artisan slipstream:webhook-ranges refresh` right after adding one, or wait a
minute. `slipstream:webhook-ranges show` prints what is stored; `slipstream:doctor` fails while a
keyword has no ranges and warns once they are older than `SLIPSTREAM_WEBHOOK_RANGES_STALE_HOURS`
(default 48). GitLab.com publishes no hook ranges, so there is no `gitlab` keyword: paste its
documented addresses.

Behind a proxy or load balancer the app must trust it (Laravel's `TrustProxies`), or the address
Slipstream sees is the proxy's and every delivery is refused — the rejected row tells you which
address it saw. An `allow`/`deny` block on the hook `location` in nginx does the same job
(`stubs/nginx-slipstream.conf` has one); in PCI mode `slipstream:doctor` warns when the in-app
list is empty, which is expected if nginx does it.

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
  (skipped in poll mode, which has no hook); with a list, fails on an entry that is neither an
  address nor a keyword and on a keyword never downloaded (both refuse every delivery), warns
  when a keyword's ranges are old.
- **hook scripts** — warns about stored hooks of several lines without `set -e`, in every mode.
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
        'where' => 'build',    // in CI when releases are built once; on the node in git mode
        'script' => '{{ npm }} ci --no-audit --no-fund && {{ npm }} run build && rm -rf node_modules',
    ],
    ['name' => 'Optimize', 'step' => 'composer', 'script' => '{{ php }} artisan optimize'],
    ['name' => 'Link storage', 'step' => 'composer', 'script' => '{{ php }} artisan storage:link || true'],
    ['name' => 'Restart Horizon', 'step' => 'fpm', 'script' => '{{ php }} artisan horizon:terminate || true'],
];
```

Each hook takes `name`, `step` and `script` (required); `position` (`before` or `after`, default
`after`); `servers` (a list of server names, default every node); `fleet_lock` (one node per
commit runs it); `enabled`; `fail_fast` (default true: a script of several lines gets
`set -e` first, so its first failing line fails the deploy rather than only its last); and
`where` (`node`, the default, or `build`: a hook that belongs to the build, run in CI when
releases are [built once](#hooks-build-time-and-node-time) and skipped on an artifact node;
only on `fetch` and `composer`). Steps run in this order: `fetch`, `link_shared`, `composer`,
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
script name: `sh slipstream-bootstrap.sh --no-fallback`. With artifact releases the stub
takes `ARTIFACTS` instead of `REPO` and runs no git or composer at all: see
[Bootstrapping from an artifact](#bootstrapping-from-an-artifact).

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
same change-control record. With artifact releases, move the pointer instead:
[Rolling back](#rolling-back). As always, rollback is code only: it does not un-migrate the
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
needs its own webhook, and a pointer to poll mode. In artifact mode the git checks make way
for the [artifact checks](#doctor-in-artifact-mode).

### Poll mode in a PCI environment

- Poll mode adds no inbound endpoint. It does add steady outbound traffic from every node to
  the git host: the `ls-remote` each check, and the fetch when the ref moves. Allow that in your
  egress rules, and nothing else. With [artifact releases](#artifact-releases-build-once-in-ci)
  that traffic goes to the store through a VPC endpoint instead, and the node holds no deploy
  key, no composer credentials and no build tool.
- The shared lock store coordinates deploys into the CDE, which puts it in scope. Give it its
  own network path and credentials.
- Fetch the shared `.env` and the deploy key from a secrets manager at boot; never bake them
  into the image. Every copy of an image is a copy of both, and if `APP_KEY` encrypts
  cardholder data it is a key under requirement 3.
- Run the scheduler as the PHP-FPM pool user, like every deploy, and keep `SLIPSTREAM_SERVER`
  out of the shared `.env`.

### Not in this release

Rollout caps (a limit on how many nodes build or swap at once) and a signed expiry on artifact
pointers (see [Replay protection](#replay-protection)) are not part of 0.6. Releases are found
through an interface, `Slipstream\Release\ReleaseSource`, with two sources bound to it: git,
and the artifact store below.

## Artifact releases: build once in CI

In git mode every node builds its own release: `git fetch`, `composer install`, the build
hooks. On a server inside a cardholder data environment that means git, composer and npm
installed and reachable from the CDE, a deploy key and Composer credentials on every node,
and third-party code — Composer plugins, Vite plugins, whatever a dependency's install script
does — running with the production `.env` within reach. Artifact mode moves all of it to CI:
one job builds the release, another signs it and puts it in a store; each node downloads the
release, verifies the signature and every file's hash, unpacks it and swaps. A node never
runs git, composer or npm, needs no deploy key, and reaches nothing but the store.

```dotenv
SLIPSTREAM_TRIGGER=poll
SLIPSTREAM_DEPLOY_REF=refs/heads/production
SLIPSTREAM_RELEASE_SOURCE=artifact
SLIPSTREAM_RELEASE_ENVIRONMENT=production
SLIPSTREAM_RELEASE_PUBLIC_KEYS=prod-2026:2Q5kK…=
SLIPSTREAM_ARTIFACTS_DISK=artifacts
SLIPSTREAM_ARTIFACTS_PREFIX=production
```

Artifact mode needs [poll mode](#poll-mode-and-auto-scaling-groups): a webhook names commits
CI may never have built, while a pointer in the store names exactly one release CI signed.
The deploy ref is one branch or one tag; a tag pattern has no pointer. The store is a
[filesystem disk](https://laravel.com/docs/filesystem) the app defines, so Slipstream carries
no AWS SDK. In `config/filesystems.php`, with `league/flysystem-aws-s3-v3` installed:

```php
'artifacts' => [
    'driver' => 's3',
    'bucket' => env('ARTIFACTS_BUCKET'),
    'region' => env('AWS_DEFAULT_REGION'),
    // No key or secret: the instance role, through the VPC endpoint.
],
```

Any disk works; a `local` disk makes a one-machine trial, and the tests run on one.

### Settings

| Variable | Default | |
|---|---|---|
| `SLIPSTREAM_RELEASE_SOURCE` | `git` | `artifact` turns artifact mode on |
| `SLIPSTREAM_RELEASE_ENVIRONMENT` | unset | The environment releases are signed for (`production`, `staging`). Bound into every signature: a pointer or manifest from another environment's store verifies, and is refused. Required |
| `SLIPSTREAM_RELEASE_PUBLIC_KEYS` | unset | `id:base64` of the Ed25519 public key nodes verify with; comma-separated, at most two (the one in use and the one it is rotating to); or `file:/etc/slipstream/release-keys` holding the same list. Required |
| `SLIPSTREAM_ARTIFACTS_DISK` | unset | The disk in `config/filesystems.php` the store is read from. Required |
| `SLIPSTREAM_ARTIFACTS_PREFIX` | empty | A key prefix inside the disk — one per environment, say |
| `SLIPSTREAM_ARTIFACTS_MAX_ARCHIVE_BYTES`, `…_MAX_UNPACKED_BYTES`, `…_MAX_ENTRIES` | 1 GiB, 4 GiB, 250 000 | What a node will download and unpack, at most |
| `SLIPSTREAM_ARTIFACTS_VERIFY_WEEKLY` | `true` | Schedule `slipstream:verify --audit` weekly |
| `SLIPSTREAM_HOOK_ENV` | `minimal` in artifact mode, `inherit` in git mode | What hooks see: `minimal` is PATH, HOME, COMPOSER_HOME, the locale and timezone variables, the proxy and CA variables (`HTTP(S)_PROXY`, `NO_PROXY`, `ALL_PROXY`, `SSL_CERT_FILE`, `SSL_CERT_DIR`) and `SLIPSTREAM_*`; `inherit` is this process's whole environment, every `.env` value included. The detached deploy always inherits. See [the hook environment](#hooks-build-time-and-node-time) for what this does and does not protect |
| `SLIPSTREAM_HOOK_ENV_PASS` | unset | More variable names minimal mode passes to hooks, comma-separated |
| `SLIPSTREAM_SIGNING_KEY` | unset | CI only: `sodium:<base64 secret>`, `file:<path>` holding that, or `kms:<key id, ARN or alias>` |

The paths a node may write into a release after unpacking it (`bootstrap/cache` unless the
publish job says otherwise) are signed into the manifest by `publish`. They excuse only
regular files the manifest does not list: a file CI signed is always checked, wherever it is.
`public/hot` is never excused (only `npm run dev` writes it, and Vite then loads every script
from the URL inside), and `public/storage` passes only as a link into the release's shared
storage, as `storage:link` makes it — whatever an older manifest lists. `artifacts.exclude`
(paths `package` leaves out, beyond its own list) and `artifacts.allow` (paths let through on
purpose: key material outside `vendor/`, or a name the never-ship rules would drop) live in
the published config, which the repository carries.

### What is in the store

Under the prefix:

```
releases/<sha>/release.tar.gz     the release
releases/<sha>/manifest.json      every file's sha256 and size, the archive's, PHP, the ref
releases/<sha>/manifest.sig       the signed statement about that manifest
refs/heads/production.json        the pointer: the release the ref is at now (written last)
```

A signature is an envelope — `{"alg":"ed25519","key_id":"prod-2026","payload":"<base64>",
"signature":"<base64>"}` — over the exact payload bytes behind a domain tag
(`slipstream-pointer\n` or `slipstream-manifest\n`), so a signed pointer can never pass as a
manifest statement or the other way round. The statement signs the manifest's sha256 and
size, the archive's sha256, the commit, the ref and the environment; the manifest itself
lists the files. The pointer signs the ref, the commit, the manifest's sha256, the
environment, a `sequence`, who published it and when, and `previous` — the release it
replaced, which bootstrap may fall back to. All three are small JSON documents you can read
with `aws s3 cp … -`.

### What a node checks

Each minute the heartbeat reads the pointer for the deploy ref and checks it: the envelope,
the key id against `SLIPSTREAM_RELEASE_PUBLIC_KEYS`, the signature, the environment, that it
names the deploy ref, and the sequence (see [Replay protection](#replay-protection)). A
pointer that fails is **refused**: the check's result is `refused` with the reason, nothing is
downloaded, no deployment row is written, the node keeps serving what it has and keeps
checking, and the audit log carries `slipstream.poll.refused` and
`slipstream.artifact.refused` with the stage and the reason, once per change. A pointer that
passes and names a new commit starts the deploy, which then:

1. reads the manifest and checks its sha256 is the one the pointer names;
2. reads the statement, verifies its signature, and checks it signs that manifest, that
   commit, this environment and the deploy ref;
3. refuses a release built for a newer PHP major.minor than the node's (an older one deploys,
   with a warning in the log);
4. streams the archive into `releases/.incoming-<timestamp>_<sha>`, hashing as it goes, and
   checks the hash and size against the manifest and the statement (after checking the disk
   has room for it);
5. unpacks it with its own tar reader — plain files and directories only; no symlinks, hard
   links or devices; no absolute paths or `..`; within the byte and entry limits — hashing
   each file as it is written, and checks that every file in the manifest is there with the
   right hash and size and that nothing else is — no file, and no directory no listed file
   is in;
6. writes `RELEASE_SHA` and `.slipstream/{manifest.json,manifest.sig,pointer.json}`, renames
   the directory into place, reads the release's hooks file, and goes on like any deploy:
   shared links, no composer (the dependencies came with the artifact), migrate, health
   check, swap, FPM reload, prune.

Any failure in 1–5 is a refusal: the download is deleted at once, never kept as a `.failed`
release (there is nothing to re-run, and the bytes are not trusted), the deployment fails with
`Refusing artifact (<stage>): <reason>`, and `slipstream.artifact.refused` is audited. A
failure after that — a hook, the health check — keeps the release as `.failed` for
inspection, as always. A verified deploy audits `slipstream.artifact.verified` with the
commit, the manifest hash, the archive hash, the file count and whether a pointer named it
(`bound`). Just before the swap the deploy records the hashes of the caches the node
generated (`bootstrap/cache/*.php`) in `.slipstream/generated.json` and audits them as
`slipstream.artifact.baseline`.

**Every deploy is tied to the pointer that named it.** A poll or bootstrap deploy carries the
pointer it started from and fetches exactly the manifest that pointer named, even if the
pointer has moved since. If the pointer moves on to another commit while the deploy runs — a
rollback, or the next release — the deploy stops before the swap, as `superseded` rather than
failed, removes its release, and the next check deploys what the pointer names now. A deploy
by commit (`slipstream:deploy --sha=`, the dashboard) is bound to the pointer when the
pointer's head or its previous names that commit; otherwise it rests on the release's own
signed statement (ref and environment checked) and says so in the log and the audit line.
The back-off after a failed deploy is keyed on the manifest the deploy was started for.

### Publishing from GitHub Actions

Two jobs. The first builds: it runs composer, npm and whatever the dependencies' install
scripts and plugins do, with no cloud credentials. The second signs and publishes: it holds
the only credentials that matter, runs nothing from the build but a hash comparison, and
boots no application code — the standalone tool `slipstream-release.php` (in the package's
`stubs/`; dependency-free: PHP with sodium and zlib, and the AWS CLI) does the signing and the
upload. Commit a copy of it to the app repository, as you do with the bootstrap stub for the
image, and refresh it when you upgrade Slipstream.

```yaml
name: release
on:
  push:
    branches: [production]

# One publish at a time per ref, never cancelled half-way.
concurrency:
  group: slipstream-${{ github.ref }}
  cancel-in-progress: false

jobs:
  build:
    runs-on: ubuntu-latest
    permissions:
      contents: read              # no id-token: this job gets no cloud role
    outputs:
      archive_sha256: ${{ steps.package.outputs.archive_sha256 }}
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4' }
      - uses: actions/setup-node@v4
        with: { node-version: 22 }
      - run: composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
      - id: package
        run: |
          php artisan slipstream:artifact package --no-install --environment=production --ref="$GITHUB_REF"
          echo "archive_sha256=$(sha256sum dist/release.tar.gz | cut -d' ' -f1)" >> "$GITHUB_OUTPUT"
      - uses: actions/upload-artifact@v4
        with: { name: release, path: dist/, if-no-files-found: error }

  publish:
    needs: build
    runs-on: ubuntu-latest
    environment: production       # its deployment-branch rule and required reviewers gate the role
    permissions:
      contents: read
      id-token: write
    steps:
      - uses: actions/checkout@v4
        with: { sparse-checkout: deploy/slipstream-release.php, sparse-checkout-cone-mode: false }
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', extensions: 'sodium, zlib' }
      - uses: actions/download-artifact@v4
        with: { name: release, path: dist }
      - run: test "$(sha256sum dist/release.tar.gz | cut -d' ' -f1)" = "${{ needs.build.outputs.archive_sha256 }}"
      - uses: aws-actions/configure-aws-credentials@v4
        with:
          role-to-assume: arn:aws:iam::123456789012:role/app-release-publisher
          aws-region: us-east-1
      - run: |
          php deploy/slipstream-release.php publish \
            --archive=dist/release.tar.gz --manifest=dist/manifest.json \
            --sha="$GITHUB_SHA" --ref="$GITHUB_REF" --environment=production \
            --keys="${{ vars.SLIPSTREAM_RELEASE_PUBLIC_KEYS }}" \
            --key=kms:alias/app-release --store=s3://app-releases/production
```

`package` runs the hooks marked `where => build` ([below](#hooks-build-time-and-node-time)),
checks the build carries `vendor/slipstream/slipstream`, and writes `dist/release.tar.gz` and
`dist/manifest.json`. It leaves out, at any depth and whatever their case, `.git`,
`node_modules`, `.aws`, `.docker`, `.ssh`, `.kube`, `.env*` (but `.env.example`),
`auth.json`, `.envrc`, `.npmrc`, `.yarnrc*`, `.sentryclirc`, `.netrc`, `.pgpass`,
`.htpasswd`, `.git-credentials` and SQLite files outside `vendor/`, and prints what those
rules dropped; at the root also `tests`, `.github`, `vendor/bin` (Composer's symlinks; artisan
never needs them) and the paths in `artifacts.exclude`. It refuses a private key or a key
store outside `vendor/` — found by content anywhere in a file (`-----BEGIN … PRIVATE
KEY-----`), so a public CA bundle such as RDS's `global-bundle.pem` still ships — unless the
path is in `artifacts.allow`; under `vendor/`, where packages ship test keys, it prints a
warning instead. It refuses what the node writes into a release itself (`RELEASE_SHA`,
`.slipstream/`). The archive is deterministic — sorted entries, zero timestamps, fixed
ownership and modes — so the same tree gives the same hash.

The build job is the untrusted side, so `publish` takes nothing on its word:

- the commit is `--sha` and the ref `--ref` (GitHub Actions sets `GITHUB_SHA` and
  `GITHUB_REF`), and a build manifest that names another of either is refused;
- the manifest it signs is rebuilt from the archive's own bytes, and the paths a node may
  write are publish's own (`--generated`, defaulting to `bootstrap/cache`), never the build's;
- the archive is read with the node's reader and limits, and refused if it carries anything
  `package` would have left out or refused, what the node writes itself
  (`bootstrap/cache/*.php` included), or a directory no file is in (every node would refuse
  it, and the commit's write-once slot would be spent); `--allow` lets a path through on
  purpose;
- both signatures are checked under the nodes' keys (`--keys`) before anything uploads, so a
  KMS alias pointing at a key no node trusts is found in CI, not by every node refusing;
- releases are **write-once**: the same bytes again (a re-run of the publish job) are
  skipped, different bytes for a published commit are refused — a build can never overwrite
  an older, approved release and poison rollbacks. To publish a commit again, push a new one;
- the pointer goes last, and only if no other publish moved it since it was read (S3
  `If-Match` on its ETag, `If-None-Match` for the first).

`php artisan slipstream:artifact sign` and `publish` (with `--sha`, `--ref`, `--generated`,
`--allow` and `--keys`, defaulting to `GITHUB_SHA`, `GITHUB_REF` and
`SLIPSTREAM_RELEASE_PUBLIC_KEYS`) do the same from
a job that has the application installed, for a local-key setup; through a filesystem disk
they check before they write, which leaves a small race the conditional S3 writes of the
standalone tool do not, so use the tool for S3. `slipstream:artifact list` shows what the
store holds.

**The trust policy.** The publish role's trust condition is the environment, not the branch:
`token.actions.githubusercontent.com:sub` equals `repo:ORG/REPO:environment:production`. A
`ref:` subject would let any workflow on the branch assume the role; the environment's
deployment-branch rule (production only) and its required reviewers are what gate the role,
and the reviewed approval is the change record (PCI DSS 6.5.1). GitHub is moving the `sub`
claim from names to repository and environment IDs for newer repositories, so read the claim
from a test run before writing the condition. The role needs `kms:Sign` and
`kms:GetPublicKey` on the key; `s3:PutObject` and `s3:GetObject` under `releases/*` and
`refs/*` of the prefix (it reads the current pointer and its ETag, and compares a re-run with
what is there); and `s3:ListBucket` on the prefix, so a key that does not exist yet reads as
missing rather than forbidden. Nothing else.

**The key.** Ed25519 only. A KMS asymmetric key (`ECC_NIST_EDWARDS25519`, `SIGN_VERIFY`)
signs with `ED25519_SHA_512` over the raw message, never leaves AWS, and gives every
signature a CloudTrail event naming the role that made it. Its key policy allows `kms:Sign`
to the publish role only, with the conditions `kms:SigningAlgorithm = ED25519_SHA_512` and
`kms:MessageType = RAW`, and `kms:GetPublicKey` to whoever provisions nodes; alarm on `Sign`
by any other principal. The nodes' key is the last 32 bytes of the DER the key exports:
`aws kms get-public-key --key-id alias/app-release --output text --query PublicKey | base64 -d | tail -c 32 | base64`,
with an id of your choosing in front. For a local key, `php artisan slipstream:artifact keygen`
prints both lines: the secret for CI's environment secrets, the public key for the shared
`.env`.

**The bucket.** Versioning on, Block Public Access on, insecure transport denied. The publish
role may write under `releases/*` and `refs/*` and nothing else; the node role may
`GetObject` and `ListBucket`, and only through the VPC gateway endpoint (`aws:SourceVpce`).
Make the write-once rule the bucket's, not only the tool's, by denying writes that do not
carry the conditional headers:

```json
{
  "Sid": "ReleasesAreWriteOnce",
  "Effect": "Deny",
  "Principal": "*",
  "Action": "s3:PutObject",
  "Resource": "arn:aws:s3:::app-releases/production/releases/*",
  "Condition": {
    "Null": { "s3:if-none-match": "true" },
    "Bool": { "s3:ObjectCreationOperation": "true" }
  }
},
{
  "Sid": "PointersMoveOnlyFromWhatWasRead",
  "Effect": "Deny",
  "Principal": "*",
  "Action": "s3:PutObject",
  "Resource": "arn:aws:s3:::app-releases/production/refs/*",
  "Condition": {
    "Null": { "s3:if-match": "true", "s3:if-none-match": "true" },
    "Bool": { "s3:ObjectCreationOperation": "true" }
  }
}
```

(With these in place `CopyObject` into those prefixes fails too, which nothing here uses.)
Object Lock in compliance mode with a default retention keeps every pointer and release
version for its retention period, so the history of what the fleet was told to run stays —
but it does not stop a new version becoming current: anyone who may write `refs/*` can make
an old signed pointer current again. What stops that is who may write `refs/*` (the publish
role only), the alarm below, and each node's [high-water mark](#replay-protection). Deny
`DeleteObject` and `DeleteObjectVersion` to all but a break-glass role. Turn on CloudTrail S3
data events for the bucket, with an alarm on any `PutObject` under `refs/*` by a principal
other than the publish role. The gateway endpoint's policy allows `GetObject` and
`ListBucket` on this bucket, plus whatever else the node needs S3 for — package mirrors and
SSM use it too; decide that explicitly.

### Hooks: build time and node time

A hook in `slipstream-hooks.php` takes `where`: `node` (the default) runs on each node, as
always; `build` runs in CI's `package` step and is skipped on an artifact node. A build-time
hook can attach to `fetch` (after) or `composer` (before or after) only, because the other
steps happen on a node. The seeded "Build assets" default is a build-time hook: in artifact
mode it runs in CI and nowhere else, and in git mode on the node as before. Hooks stored in
the database are always node-time, and `slipstream:doctor` warns about them on an artifact
node — they are a path for code nobody signed. Keep hooks in the file, which ships inside the
signed release:

```php
return [
    ['name' => 'Build assets', 'step' => 'composer', 'where' => 'build',
        'script' => "{{ npm }} ci --no-audit --no-fund\n{{ npm }} run build\nrm -rf node_modules"],
    ['name' => 'Optimize', 'step' => 'composer', 'script' => '{{ php }} artisan optimize'],
];
```

On an artifact node `{{ npm }}` and `{{ composer }}` are shell functions that fail with
"npm does not run on an artifact node; mark the hook where => build", so a hook that should
have been build-time fails the deploy instead of quietly needing a tool the node should not
have. The hooks file must be the release's own: in artifact mode `SLIPSTREAM_HOOKS_FILE` may
not be absolute, contain `..`, or resolve (through a link such as `storage/`) outside the
release.

**The hook environment.** Hooks see a minimal environment (`SLIPSTREAM_HOOK_ENV`): PATH,
HOME, the locale, the proxy and CA variables, `SLIPSTREAM_*` and what
`SLIPSTREAM_HOOK_ENV_PASS` names. `artisan` reads the `.env` itself, so a hook that runs `php
artisan` loses nothing. Be clear about what this buys: it keeps secrets out of hook output,
which the deploy log stores raw. It is not isolation — a hook runs as the deploy user, who
can read the shared `.env` — so do not record it as a compensating control. The detached
deploy process itself always inherits its environment: it is Slipstream's own, and the one
that downloads from the store, so it keeps its proxy and AWS settings.

Build-time values such as `VITE_*` come from CI's environment, not the shared `.env`; lint
them for secrets, since a built asset is public. `composer.lock` and `package-lock.json` ship
inside the archive, hashed like every other file, so the signed manifest is also the
release's inventory of components.

### Verifying the live release

Both checks compare the release with its signed manifest: the statement under trusted keys,
every file the manifest lists re-hashed, nothing added, and the generated caches
(`bootstrap/cache/*.php`) against the hashes the deploy recorded just before the swap. A
symlink is allowed only where the deploy makes one, and only leading exactly where it points
it: `storage` to the app root's shared `storage`, `public/storage` to `storage/app/public`
(and each `filesystems.links` entry to its target), the `.env` and `auth.json` to the shared
copies, each shared path to its shared directory. A storage link aimed at `storage/logs`, or
at the app root, would serve logs, sessions or the `.env` over HTTP; a regular `.env` file in
place of the link is configuration nobody deployed. All of it is drift. Paths the signed
manifest marks as node-written excuse only regular files it does not list, and never
anything under `public/hot` or `public/storage`. In artifact mode a release without a usable
manifest is drift, not "nothing to verify": deleting it must not silence the check. So is a
lost record of the cache hashes:
`slipstream:verify` compares the record with the hash the deploy wrote on its deployment row,
and `verify-tree` counts a missing record as drift (every deploy since 0.6.1 writes one).

**The PCI DSS 11.5.2 control is `verify-tree`, run from outside the release.** The release
tree belongs to the deploy user, who is also the PHP-FPM pool user, and `slipstream:verify`
runs inside the app, from the very release it checks — anything that can write the tree
(code execution in the app) can patch the verifier. So run the standalone tool from the
image's root-owned copy, as root, with keys from a root-owned file (never the shared `.env`,
which the pool user can write):

```ini
# /etc/systemd/system/slipstream-verify.service
[Unit]
Description=Check the live release against its signed manifest

[Service]
Type=oneshot
ExecStart=/usr/bin/php /opt/slipstream/slipstream-release.php verify-tree \
    --release=/var/www/app/current --environment=production \
    --keys=file:/etc/slipstream/release-keys --shared=public/uploads=shared/uploads \
    --state-dir=/var/lib/slipstream-verify --json

# /etc/systemd/system/slipstream-verify.timer
[Timer]
OnCalendar=hourly
Persistent=true

[Install]
WantedBy=timers.target
```

It prints one line per run (`artifact.verify_ok` or `artifact.verify_drift`, as JSON with
`--json`) to the journal; ship it with the rest of the logs and alarm on `verify_drift`, and
on a day without a `verify_ok`. Exit 0 clean, 1 drift, 2 on a usage error.

- **`--state-dir` is what makes the root-run check hold.** It pins each release's manifest
  hash and recorded cache hashes the first time it sees it; without it the record of the
  caches inside the release is only as safe as the release (the in-app check compares it
  with the deployment row, which the pool user can write too), and the tool says so on
  stderr. Running it hourly keeps the window before the first look short.
- `--shared` names the shared paths the deploy links, each with its shared directory under
  the app root (`public/uploads=shared/uploads`): they are not in the shared `.env`, and the
  release's own config is the build's word. A path given without a target is allowed to link
  anywhere.
- The app root is the directory that holds `releases/`, found from where `--release` really
  leads (`/var/www/app/current` to `/var/www/app/releases/<dir>`); `--app-root` overrides it.
  Without one, where `storage`, the `.env`, `auth.json` and the shared paths lead is not
  checked, and the tool says so. When `filesystems.links` makes more storage links than
  `public/storage`, name each with its target: `--links=public/media=storage/app/media`.

The in-app check stays for convenience, and `slipstream:doctor` runs it (**live release**):

```bash
php artisan slipstream:verify              # the live release
php artisan slipstream:verify --json       # modified, missing, unlisted, links, generated
php artisan slipstream:verify --release=/var/www/app/releases/20261019_120000_ab12cd3
```

Exit 0 clean, 1 on drift (in artifact mode, including a missing or unreadable manifest), 2
with nothing to compare in git mode. In artifact mode it runs weekly with `--audit`, writing
`slipstream.artifact.verify_ok` or `slipstream.artifact.verify_drift` to the audit log. Keep
`optimize` before the swap (the default hooks do): caches regenerated later are reported as
changed since the deploy.

### Bootstrapping from an artifact

The image needs PHP with sodium and zlib, the AWS CLI, nginx, supervisor and both stubs —
`slipstream-bootstrap.sh` and `slipstream-release.php` side by side — and no git, composer or
node. The shared `.env` comes from the secrets manager at boot as before; there is no deploy
key. With `SLIPSTREAM_RELEASE_SOURCE=artifact` in that `.env`, the stub takes `ARTIFACTS`
instead of `REPO`:

```sh
sudo -u deploy -H APP_ROOT="$APP_ROOT" ARTIFACTS=s3://app-releases/production \
    sh /opt/slipstream/slipstream-bootstrap.sh
```

It fetches the pointer and checks it with `slipstream-release.php verify-pointer` (the same
checks a node applies), fetches the release and checks and unpacks it with `verify-release`
— the statement and its ref, the archive's hash, every file's — and only then runs `php
artisan slipstream:bootstrap` from it. Nothing from the store runs before it verifies, and no
object is downloaded that is larger than it can honestly be (the pointer and statement 64 KiB,
the manifest 64 MiB, the archive `SLIPSTREAM_ARTIFACTS_MAX_ARCHIVE_BYTES`). Bootstrap skips
the mirror step and deploys the pointer's release.

If the pointer moves on while `slipstream:bootstrap` deploys (the deploy ends superseded),
bootstrap follows it to the new head. If the release does not verify (in the stub) or its
deploy fails (in `slipstream:bootstrap`), they fall back to the `previous` of the pointer as it
is then, bound to the manifest the signed pointer names for it. That is the only fallback in
artifact mode: the shared store's last-good record plays no part.
`--no-fallback` (after the script name or in `BOOTSTRAP_ARGS`) stops at the head instead, in
both. A pointer written by a rollback has no previous. `RELEASE_TOOL` names the tool when it is
not beside the stub.

### Rolling back

Move the pointer: `php artisan slipstream:artifact publish --rollback --sha=<sha>
--ref=refs/heads/production` (or `slipstream-release.php rollback --sha=… --ref=… --keys=… …`
from CI) writes a new, higher pointer naming a release already in the store, after checking
that its statement verifies for this environment and was built for this ref, and uploads
nothing. A deploy still building the release you rolled away from stops before its swap. Every node converges on its next check,
and the move is a signed, logged change like a release. On one node, `php artisan
slipstream:rollback` re-verifies the previous release against its own
`.slipstream/manifest.sig` before the flip, and pins the node as in poll mode.

### Replay protection

A publish sets the pointer's `sequence` to the current one plus one, or the time, whichever
is higher. A node accepts a pointer whose sequence is at least the highest it has accepted
for that ref and refuses a lower one ("sequence N is below M, which this node already
accepted; publish a rollback rather than put an old pointer back"), one more than a day in
the future, and one whose sequence equals its mark but names another release (the
conditional pointer write means two publishes can never share a sequence). The high-water
mark is the accepted signed pointer itself, kept in the node's settings
(`artifact_sequence:<ref>`) and in the shared store, under the store's lock, so a pointer
replaced with an older copy is refused by every node that saw the newer one. The mark is
verified whenever it is read: only a pointer the release key signed, for this ref and
environment, can raise it. Anything else stored there — a number written by hand, just under
a day ahead or far beyond — counts for nothing and is audited once
(`slipstream.artifact.mark_ignored`), so no write to the shared store can stop the fleet
deploying. One exception covers the upgrade: the bare mark 0.6.0 or 0.6.1 kept in the node's
own database, if it is already in the past, is a floor until the next accepted pointer
replaces it. Every acceptance re-checks both copies and writes back one that is missing,
garbled or unsigned, so a wiped shared mark is restored within a minute and fresh nodes keep
their floor. Refusals are audited once per change, not once a minute.

What remains: a fresh instance has no mark, and accepts whatever signed pointer the store
holds — and the stub runs that release's artisan before the app-level mark is consulted. So
the control is who may write `refs/*` (the publish role, alarmed in CloudTrail), and the
mark is the signal: alarm on `slipstream.artifact.refused`. A signed pointer expiry, re-signed
on a schedule, would close the gap and is planned for 0.7.

### Rotating the key

Add the new key as the second entry of `SLIPSTREAM_RELEASE_PUBLIC_KEYS` on every node, switch
CI to sign with it, then drop the old id once nothing live was signed with it. A release's
statement verifies only while its key is listed, and `slipstream:verify` says so.

### Doctor in artifact mode

- **release source** — fails unless the trigger is poll, `ext-sodium` is loaded, the
  environment, the keys and the disk are set, and the deploy ref is one ref; passes with the
  disk, prefix, environment and key ids.
- **artifact pointer** — reads and checks the pointer without accepting it; fails with the
  reason, or with the store's error and the IAM hint; warns when it was published in the
  future.
- **live release** — the `slipstream:verify` comparison; fails when a release an artifact
  deploy wrote has lost its manifest, warns when the release was built from git.
- **hook sources** — warns about stored hooks (unsigned) and, in PCI mode, about `git`,
  `composer` or `npm` on the PATH.
- **git mirror** and **package committed** are skipped; **deploy trigger** reports the pointer
  in place of `ls-remote`; `slipstream:provision` does not look for build tools.

## Upgrading

### To 0.6.3

Small follow-ups from a third review of artifact mode; git mode is unchanged.

- **Links are held to their exact targets.** Both verifiers now require `storage` to link to
  the app root's shared `storage`, `public/storage` to `storage/app/public`, the `.env` and
  `auth.json` to the shared copies, and each shared path to its shared directory; a regular
  `.env` in the release is drift. For `verify-tree`, give `--shared` its targets
  (`--shared=public/uploads=shared/uploads`); the app root is found from `releases/<dir>`, or
  pass `--app-root`. `--links` now takes `path=target` pairs and adds to `public/storage`;
  any other name without a target exits 2.
- **`verify-tree` without `--state-dir`** now says on stderr that the cache record is only as
  safe as the release. Keep `--state-dir` in the timer.
- **Upgrade window closed:** the bare mark 0.6.0 or 0.6.1 kept in the node's own database is
  a floor again until the next accepted pointer replaces it. Every acceptance now restores a
  missing or garbled shared mark.
- **Publish refuses an archive with a directory no file is in**, which every node refuses.
- Copy the new `slipstream-release.php` into the image and the repository. No migration.

### To 0.6.2

Follow-ups from a second review of artifact mode. Nothing here changes git mode, except the
nginx stub.

- **nginx:** the stub now hands only `index.php` to PHP-FPM and answers any other `.php` with
  a 404. Do the same in your server block (`location ~ ^/index\.php(/|$)` for FPM, then
  `location ~ \.php$ { return 404; }`): otherwise a `.php` that lands in
  `storage/app/public` runs as `/storage/x.php`, in any mode.
- **Node-written paths:** `publish --generated` defaults to `bootstrap/cache` only. Nodes never
  excuse `public/hot`, and accept `public/storage` only as a link into the shared storage —
  for releases already signed with 0.6.1's defaults too. `verify-tree --links` names more
  storage links when `filesystems.links` adds any.
- **The high-water mark** is now the accepted signed pointer itself, verified on every read.
  Marks written by 0.6.0 and 0.6.1 are ignored (audited once as
  `slipstream.artifact.mark_ignored`) and replaced by the next pointer each node accepts.
- **Cache records:** each deploy writes the hash of `.slipstream/generated.json` on its
  deployment row; `slipstream:verify` reports a record deleted or rewritten since, and
  `verify-tree` reports a missing one (releases deployed by 0.6.0 have none: redeploy them).
- **Publishing** needs `--ref` (default `GITHUB_REF`) as well as `--sha` in
  `slipstream:artifact publish` and `sign`, and refuses a build for another ref. `package` and
  `publish` refuse `RELEASE_SHA`, `.slipstream/` and `bootstrap/cache/*.php`; names match
  whatever their case; `.netrc`, `.pgpass`, `.htpasswd`, `.git-credentials` and the `.aws`,
  `.docker`, `.ssh` and `.kube` directories are left out; keys are found anywhere in a file,
  and under `vendor/` they are a warning. `artifacts.allow` and `--allow` now let a never-ship
  name through too.
- **Deploys:** a pointer that turns bad mid-deploy no longer aborts it (only a verified newer
  pointer naming another commit does); unpacking refuses a directory no listed file is in;
  stale downloads are swept before each fetch; a refused `--sha` deploy no longer backs off the
  build the pointer names; `slipstream:status` shows the same back-off the poller applies;
  bootstrap follows a pointer that moves while it deploys.
- Copy the new `slipstream-release.php` into the image and the repository. No migration.

### To 0.6.1

Fixes from a review of artifact mode. Git mode changes in one place only: stored default
hooks are no longer rewritten (last point).

- **Publishing names its commit.** `publish` and `sign` need `--sha` (GitHub Actions sets
  `GITHUB_SHA`, which is the default) and refuse a build that names another commit. The
  standalone tool also needs `--keys`, the nodes' `SLIPSTREAM_RELEASE_PUBLIC_KEYS`: it checks
  every signature against them before anything uploads. Add both to the workflow's publish
  step.
- **Releases are write-once.** A commit already published with different bytes is refused;
  to publish again, push a new commit. A re-run of the publish job alone is fine. Add the two
  `Deny` statements from [the bucket](#publishing-from-github-actions) to the bucket policy,
  and `s3:GetObject` and `s3:ListBucket` to the publish role. The standalone tool now writes
  with `aws s3api put-object`, so the CI image needs a current AWS CLI v2.
- **The paths a node may write come from publish** (`--generated`, defaulting to
  `bootstrap/cache`, `public/storage`, `public/hot`), never from the build's manifest or the
  release's config; and every file the manifest lists is checked, wherever it is.
- **Verification.** `slipstream:verify` exits 1 for a release without a usable manifest in
  artifact mode, so a `current` built from git reports drift until its first artifact deploy.
  It also compares `bootstrap/cache/*.php` with the hashes recorded at deploy (releases
  deployed by 0.6.0 have none). Set up `slipstream-release.php verify-tree` from a root timer
  — [Verifying the live release](#verifying-the-live-release) — as the 11.5.2 control.
- **Packaging** drops `.env*`, `auth.json`, `.npmrc` and the rest at any depth and refuses a
  private key outside `vendor/`; list a deliberate one in `artifacts.allow`.
- **Hooks** on an artifact node now really get the minimal environment (0.6.0 passed
  everything); a hook that needs another variable names it in `SLIPSTREAM_HOOK_ENV_PASS`. The
  detached deploy inherits its environment again, so proxies and AWS settings reach the
  download.
- **Deploys bind to the pointer**: a poll deploy stops as `superseded` if the pointer moves to
  another commit before its swap; the statement's ref must be the deploy ref; bootstrap falls
  back to the pointer's previous (stub included), not the shared last-good record.
- `SLIPSTREAM_RELEASE_PUBLIC_KEYS=file:` must be an absolute path; `SLIPSTREAM_HOOKS_FILE`
  may not leave the release in artifact mode.
- Copy the new `slipstream-release.php` and `slipstream-bootstrap.sh` into the image and the
  repository; `verify-release` now needs `--ref`, which the new bootstrap stub passes.
- **Stored default hooks are no longer rewritten** (0.5.2 rewrote untouched ones in place, so
  a node still on older code during an upgrade took the new text for an edited hook and ran
  it beside the hooks file). An untouched copy of any earlier default runs the current text;
  `slipstream:hooks` shows the text that runs.
- No migration.

### To 0.6.0

- Nothing changes unless `SLIPSTREAM_RELEASE_SOURCE=artifact`. Git mode deploys, hooks and
  the hook environment behave as in 0.5.
- New config: the `release` and `artifacts` blocks and `deploy.hook_env`
  (`slipstream:publish --config` to see them; the in-code defaults apply without them).
- The hooks file takes `where` (`node` or `build`), and the seeded "Build assets" default is
  build-time: in git mode it runs on the node as before; in artifact mode it runs in CI. Mark
  the build hooks in your own hooks file `where => build` before switching a fleet to
  artifact mode, or their deploys fail on the npm guard.
- `illuminate/filesystem` is now required (the store is a filesystem disk). Artifact nodes
  need `ext-sodium` and `ext-zlib`; an S3 store needs `league/flysystem-aws-s3-v3` in the
  app.
- Copy both stubs — `slipstream-bootstrap.sh` and the new `slipstream-release.php` — into
  the image, and the latter into the repository for CI.
- No migration.

### To 0.5.2

- **Multi-line default hooks now fail fast.** The seeded "Build assets", "Restart Horizon" and
  "Restart Reverb" scripts begin with `set -e`, so a failing `npm run build` fails the deploy
  instead of passing on `rm -rf node_modules`'s status. Untouched copies on existing installs
  are brought up to date in place by `install`, `bootstrap`, or the first heartbeat after the
  upgrade (once per database, recorded as `default_hooks_version`); a copy you edited is left
  alone — add `set -e` yourself, and `slipstream:doctor` points at stored hooks that lack it.
- **Multi-line scripts in `slipstream-hooks.php` fail fast too**, unless a hook sets
  `'fail_fast' => false`. Single-line scripts are untouched.
- `SLIPSTREAM_WEBHOOK_ALLOWED_IPS` accepts `github` and `bitbucket`. After adding a keyword run
  `php artisan slipstream:webhook-ranges refresh`, or wait a minute for the heartbeat; until the
  first download the hook refuses every delivery.
- `SLIPSTREAM_HOOKS_PATH` is optional; unset, the hook endpoints stay at `{path}/hooks/*`.
- No migration.

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
composer stub:build     # regenerate stubs/slipstream-release.php after touching src/Release/Core
```

`stubs/slipstream-release.php` is generated from `src/Release/Core/*` and
`stubs/src/release-main.php`, and committed; `ReleaseStubTest` fails when it is out of date.
The symlink, shell-stub and tar tests skip on Windows: run the suite on Linux (WSL does) before
a release.

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
