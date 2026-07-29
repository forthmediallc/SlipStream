# Slipstream

Zero-downtime deploys for Laravel — self-hosted, no SSH to your servers.

Slipstream installs into your app. On push (or from the dashboard), each server builds a fresh release, runs health checks, then atomically swaps traffic to the new code. Failed builds never go live. A gated dashboard shows fleet status, live logs, webhooks, and settings.

## Requirements

- PHP 8.2+ (8.3+ recommended for Laravel 13)
- Laravel 11, 12, or 13
- Livewire 3.5+ or 4.x
- Linux in production (`symlink` swap, `systemctl`, `setsid`)
- Shared database across servers (multi-server); cache driver that supports locks (Redis or database) for fleet migrations

## Installation

```bash
composer require slipstream/slipstream
php artisan slipstream:install
```

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
[Security](#security)). Everything else requires passing the gate above.

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
| `webhook.allow_redeploy_same_sha` | Let a webhook redeploy the commit already live here (default off) |
| `server_name` | This node’s fleet identity (`SLIPSTREAM_SERVER`) |
| `views.*` | Enable/disable dashboard sections |
| `deploy.keep_releases` | How many releases to retain (default 5) |

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

Point your web server document root at `{app_root}/current/public`.

Ensure the scheduler runs (heartbeats and future scheduled work):

```cron
* * * * * cd /path/to/current && php artisan schedule:run >> /dev/null 2>&1
```

## Webhooks

Dashboard → **Webhook** shows the endpoint and secret for this server.

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
| `slipstream:install` | Publish assets, migrate, seed defaults |
| `slipstream:publish` | Re-publish config / assets / migrations |
| `slipstream:provision` | Deploy key, bare mirror, directory layout |
| `slipstream:deploy` | Run a deploy (or `--deployment=`) |
| `slipstream:rollback` | Point `current` at the previous release |
| `slipstream:healthcheck` | Pre-swap checks (DB, Redis, custom) |
| `slipstream:heartbeat` | Refresh this node in the fleet registry, publish its `.env` snapshot, and apply any queued env push (scheduled every minute) |
| `slipstream:prune` | Trim old deploy events + webhook deliveries + terminal env pushes; fail stuck deploys/applies (scheduled daily) |
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
- Pause a server to accept webhooks but skip builds.
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

## Security notes

- The hook endpoints (`/hooks/deploy`, `/hooks/relay`, `/hooks/env-sync`, `/hooks/deploy-sync`)
  are the only unauthenticated routes: HMAC/token verified, CSRF-exempt (so provider POSTs work
  out of the box), rate limited, and body-capped. No host-app CSRF configuration is required.
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

## License

MIT
