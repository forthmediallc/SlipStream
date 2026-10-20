<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Database\Tables;
use Slipstream\Deploy\MigrationCoordinator;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Fleet\NodeHold;
use Slipstream\Fleet\Poller;
use Slipstream\Release\ArtifactSequence;
use Slipstream\Release\ArtifactSource;
use Slipstream\Release\ArtifactStore;
use Slipstream\Release\DeployRef;
use Slipstream\Environment\EnvSync;
use Slipstream\Fleet\Drift;
use Slipstream\Models\Deployment;
use Slipstream\Models\EnvPush;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;

/**
 * The dashboard's front page on one screen: this node, the fleet and its
 * drift, what is running, where every server last landed, what failed today,
 * and the pipelines that decide which pushes deploy.
 */
class StatusCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:status {--json : Machine-readable output}';

    protected $description = 'Fleet overview: servers and drift, in-flight and latest deployments, recent failures, pipelines';

    public function handle(SettingsRepository $settings, Drift $drift, Tables $tables): int
    {
        $self = Slipstream::serverName();
        $pci = Slipstream::pciMode();
        $database = $tables->activeConnection();
        $separateDatabase = ! $tables->sameDatabase($database, $tables->defaultConnection());
        $staleAfter = (int) config('slipstream.fleet.heartbeat_stale_seconds', 120);
        $summary = $drift->summary($staleAfter);

        $inFlight = Deployment::query()
            ->whereIn('status', [Deployment::STATUS_QUEUED, Deployment::STATUS_PENDING, Deployment::STATUS_BUILDING])
            ->orderBy('id')
            ->get();

        $latestIds = Deployment::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('status', [Deployment::STATUS_DEPLOYED, Deployment::STATUS_FAILED, Deployment::STATUS_ROLLED_BACK])
            ->groupBy('server_name')
            ->pluck('id');
        $latest = Deployment::query()->whereIn('id', $latestIds)->orderBy('server_name')->get();

        $failedQuery = Deployment::query()
            ->where('status', Deployment::STATUS_FAILED)
            ->where('finished_at', '>=', now()->subDay());
        $failedCount = (clone $failedQuery)->count();
        $failed = $failedQuery->orderByDesc('id')->limit(5)->get();

        $pipelines = Pipeline::query()->orderBy('name')->get();
        $relay = filter_var($settings->get('relay_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $syncOn = EnvSync::enabled();
        $pendingPushes = $syncOn ? EnvPush::query()->where('status', EnvPush::STATUS_PENDING)->count() : 0;
        $hook = Slipstream::hookUrl('deploy');

        if ($this->option('json')) {
            $this->json([
                'node' => $self,
                'pci_mode' => $pci,
                'database' => ['connection' => $database, 'separate' => $separateDatabase, 'target' => $tables->describe($database)],
                'trigger' => $this->triggerState(),
                'migrations' => $this->migrationsState(),
                'repository' => $settings->repoUrl(),
                'branch' => $settings->branch(),
                'keep_releases' => $settings->keepReleases(),
                'webhook_url' => $hook,
                'relay_enabled' => $pci ? false : $relay,
                'env_sync' => $syncOn,
                'pending_env_pushes' => $pendingPushes,
                'heartbeat_stale_seconds' => $staleAfter,
                'servers' => $summary['servers']->map(fn (Server $s) => $this->serverJson($s, $staleAfter))->values()->all(),
                'drift' => [
                    'has_drift' => $summary['has_drift'],
                    'canonical_sha' => $summary['canonical_sha'],
                    'stale' => $summary['stale']->pluck('server_name')->values()->all(),
                    'offline' => $summary['offline']->pluck('server_name')->values()->all(),
                ],
                'in_flight' => $inFlight->map(fn (Deployment $d) => $this->deploymentJson($d))->values()->all(),
                'latest' => $latest->map(fn (Deployment $d) => $this->deploymentJson($d))->values()->all(),
                'failed_24h' => ['count' => $failedCount, 'recent' => $failed->map(fn (Deployment $d) => $this->deploymentJson($d))->values()->all()],
                'pipelines' => $pipelines->map(fn (Pipeline $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'branch_pattern' => $p->branch_pattern,
                    'auto_deploy' => (bool) $p->auto_deploy,
                    'enabled' => (bool) $p->enabled,
                    'servers' => $p->server_names,
                ])->values()->all(),
            ]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Node', $self.($pci ? ' · PCI mode' : ''));
        $this->components->twoColumnDetail('Database', $database.($separateDatabase ? ' (separate from the app database)' : ' (the app database)'));
        $this->components->twoColumnDetail('Repository', ($settings->repoUrl() ?: 'not set').' @ '.$settings->branch().' · keep '.$settings->keepReleases());
        $this->renderTrigger($hook);
        $migrations = $this->migrationsState();
        $this->components->twoColumnDetail('Migrations', $migrations['command'] === null
            ? 'seeded "Run migrations" hook (no migrate step)'
            : $migrations['command'].' · '.($migrations['enabled_here'] ? 'this node may run it, once per commit' : 'off on this node'));
        if (! $pci) {
            $this->components->twoColumnDetail('Relay', $relay ? 'on' : 'off');
            $this->components->twoColumnDetail('Env sync', $syncOn ? 'on'.($pendingPushes > 0 ? " · {$pendingPushes} pending push(es)" : '') : 'off');
        }

        $this->newLine();
        $this->line("<options=bold>Servers</> (offline after {$staleAfter}s without a heartbeat)");
        if ($summary['servers']->isEmpty()) {
            $this->line('  none registered yet — the first heartbeat or webhook adds this node');
        } else {
            $this->table($this->serverHeaders, $this->serverRows($summary['servers'], $staleAfter));
            $this->renderDrift($summary);
        }

        $this->newLine();
        $this->line('<options=bold>In flight</>');
        if ($inFlight->isEmpty()) {
            $this->line('  nothing queued, pending or building');
        } else {
            $this->table($this->deploymentHeaders, $inFlight->map(fn (Deployment $d) => $this->deploymentRow($d))->all());
        }

        $this->newLine();
        $this->line('<options=bold>Latest finished per server</>');
        if ($latest->isEmpty()) {
            $this->line('  no finished deployments yet');
        } else {
            $this->table($this->deploymentHeaders, $latest->map(fn (Deployment $d) => $this->deploymentRow($d))->all());
        }

        $this->newLine();
        if ($failedCount === 0) {
            $this->line('<options=bold>Failed in the last 24h:</> none');
        } else {
            $this->line("<options=bold>Failed in the last 24h:</> {$failedCount}".($failedCount > $failed->count() ? ' (newest 5 shown)' : ''));
            $this->table($this->deploymentHeaders, $failed->map(fn (Deployment $d) => $this->deploymentRow($d))->all());
        }

        $this->newLine();
        $this->line('<options=bold>Pipelines</>');
        if ($pipelines->isEmpty()) {
            $this->line('  none — pushes are ignored until one exists: slipstream:pipelines add --name=main --branch=main');
        } else {
            $this->table(
                ['ID', 'Name', 'Branch pattern', 'Auto-deploy', 'Enabled', 'Servers'],
                $pipelines->map(fn (Pipeline $p) => [
                    '#'.$p->id, $p->name, $p->branch_pattern,
                    $p->auto_deploy ? 'yes' : 'no', $p->enabled ? 'yes' : 'no',
                    $p->server_names ? implode(', ', $p->server_names) : 'all',
                ])->all()
            );
        }

        return self::SUCCESS;
    }
    /**
     * How deploys start here and, in poll mode, what the last check saw and
     * decided: the inputs to "why is this node running what it runs".
     *
     * @return array<string, mixed>
     */
    protected function triggerState(): array
    {
        $poll = Slipstream::pollMode();
        $server = Server::query()->where('server_name', Slipstream::serverName())->first();
        $running = app(Heartbeat::class)->readCurrentSha(Slipstream::appRoot());

        try {
            $ref = $poll ? DeployRef::fromConfig()->describe() : null;
            $refError = null;
        } catch (\Throwable $e) {
            $ref = null;
            $refError = $e->getMessage();
        }

        $backoff = null;
        if ($poll && $server?->poll_seen_sha && strtolower((string) $running) !== strtolower($server->poll_seen_sha)) {
            $state = app(Poller::class)->backoff($server->poll_seen_sha);
            $backoff = $state === null ? null : ['failures' => $state['failures'], 'until' => $state['until']->toIso8601String()];
        }

        $artifact = null;
        if (Slipstream::artifactMode()) {
            $store = app(ArtifactStore::class);
            $artifact = [
                'disk' => $store->diskName(), 'prefix' => $store->prefix(), 'environment' => Slipstream::releaseEnvironment(),
                'sha' => null, 'sequence' => null, 'published_at' => null, 'by' => null, 'key_id' => null, 'high_water' => null, 'error' => null,
            ];
            try {
                $deployRef = DeployRef::fromConfig();
                $artifact['high_water'] = app(ArtifactSequence::class)->highWater($deployRef)['sequence'];
                $pointer = app(ArtifactSource::class)->pointer($deployRef, accept: false);
                $artifact = ['sha' => $pointer->sha, 'sequence' => $pointer->sequence, 'published_at' => $pointer->publishedAt, 'by' => $pointer->by, 'key_id' => $pointer->keyId] + $artifact;
            } catch (\Throwable $e) {
                $artifact['error'] = $e->getMessage();
            }
        }

        return [
            'mode' => Slipstream::triggerMode(),
            'source' => Slipstream::releaseSource(),
            'artifact' => $artifact,
            'ref' => $ref,
            'ref_error' => $refError,
            'running_sha' => $running,
            'last_poll_at' => $server?->poll_checked_at?->toIso8601String(),
            'next_poll_at' => $server?->poll_next_at?->toIso8601String(),
            'result' => $server?->poll_result,
            'detail' => $server?->poll_detail,
            'seen_sha' => $server?->poll_seen_sha,
            'seen_ref' => $server?->poll_seen_ref,
            'hold' => app(NodeHold::class)->reason($server),
            'pinned_sha' => $server?->isPinned() ? $server->pinned_sha : null,
            'backoff' => $backoff,
        ];
    }

    /**
     * @return array{command: ?string, enabled_here: bool, lock_store: string}
     */
    protected function migrationsState(): array
    {
        return [
            'command' => MigrationCoordinator::command(),
            'enabled_here' => MigrationCoordinator::enabledHere(),
            'lock_store' => \Slipstream\Fleet\SharedStore::name(),
        ];
    }

    protected function renderTrigger(string $hook): void
    {
        $state = $this->triggerState();

        if ($state['mode'] !== 'poll') {
            $this->components->twoColumnDetail('Trigger', 'webhook');
            $this->components->twoColumnDetail('Webhook', $hook);

            return;
        }

        $this->components->twoColumnDetail('Trigger', 'poll · '.($state['ref'] ?? 'invalid ref: '.$state['ref_error']));
        if ($state['artifact'] !== null) {
            $artifact = $state['artifact'];
            $this->components->twoColumnDetail('Source', 'artifact · disk '.($artifact['disk'] ?: '(unset)').($artifact['prefix'] !== '' ? ' · prefix '.$artifact['prefix'] : '').' · environment '.($artifact['environment'] ?? '(unset)'));
            $this->components->twoColumnDetail('Pointer', $artifact['error'] !== null
                ? 'unreadable: '.$artifact['error']
                : $this->shortSha($artifact['sha']).' · sequence '.$artifact['sequence'].' · published '.$artifact['published_at'].' by '.$artifact['by'].' · key '.$artifact['key_id']);
        }
        $this->components->twoColumnDetail('Running', $this->shortSha($state['running_sha']));
        $this->components->twoColumnDetail(
            'Last poll',
            $state['last_poll_at'] === null
                ? 'never — the minute heartbeat runs the check'
                : $this->ago(\Illuminate\Support\Carbon::parse($state['last_poll_at'])).' ago · '.$state['result'].' · '.$state['detail']
        );
        if ($state['seen_sha'] !== null) {
            $this->components->twoColumnDetail('Ref last seen', preg_replace('#^refs/(heads|tags)/#', '', (string) $state['seen_ref']).' at '.$this->shortSha($state['seen_sha']));
        }
        if ($state['hold'] !== null) {
            $this->components->warn('Held: '.$state['hold'].($state['pinned_sha'] ? '. Resume with: php artisan slipstream:servers resume' : ''));
        }
        if ($state['backoff'] !== null) {
            $this->components->warn(sprintf(
                'Back-off: %s failed %d× here; next try %s, or sooner if the ref moves. Force it: php artisan slipstream:deploy --sha=%s',
                $this->shortSha($state['seen_sha']),
                $state['backoff']['failures'],
                \Illuminate\Support\Carbon::parse($state['backoff']['until'])->toDateTimeString(),
                $state['seen_sha']
            ));
        }
    }
}
