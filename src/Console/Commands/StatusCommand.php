<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
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

    public function handle(SettingsRepository $settings, Drift $drift): int
    {
        $self = Slipstream::serverName();
        $pci = Slipstream::pciMode();
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
        $hook = url('/'.Slipstream::path().'/hooks/deploy');

        if ($this->option('json')) {
            $this->json([
                'node' => $self,
                'pci_mode' => $pci,
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
        $this->components->twoColumnDetail('Repository', ($settings->repoUrl() ?: 'not set').' @ '.$settings->branch().' · keep '.$settings->keepReleases());
        $this->components->twoColumnDetail('Webhook', $hook);
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
}
