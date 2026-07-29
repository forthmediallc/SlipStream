<?php

namespace Slipstream\Livewire;

use Illuminate\Support\Collection;
use Livewire\WithPagination;
use Slipstream\Fleet\DeployQueue;
use Slipstream\Fleet\Drift;
use Slipstream\Models\Deployment;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\Activity;

class DeploymentsIndex extends SlipstreamComponent
{
    use WithPagination;

    public string $statusFilter = '';

    public string $viewMode = 'list'; // list | fleet

    /**
     * Deploy the default branch on every eligible server: this node runs
     * immediately, peers get a queued row + signed nudge (heartbeat backstop).
     */
    public function deployNow(SettingsRepository $settings, DeployQueue $queue): void
    {
        $result = $queue->queue([
            'branch' => $settings->branch(),
            'trigger' => Deployment::TRIGGER_MANUAL,
            'initiator_user_id' => auth()->id(),
        ]);

        Activity::log('deploy.started', [
            'deployment' => $result['local']?->id,
            'batch' => $result['batch'],
            'queued_for' => $result['queued'],
        ]);

        $this->flashFleetResult('Deploy', $result);
    }

    /**
     * Redeploy a specific past release by its SHA on every eligible server
     * (fresh deployment records). The pinned SHA makes this the cleanest way
     * to converge a drifted fleet.
     */
    public function redeploy(int $deploymentId, DeployQueue $queue): void
    {
        $source = Deployment::query()->find($deploymentId);
        if (! $source || ! $source->sha) {
            session()->flash('slipstream_error', 'Cannot redeploy: original SHA unknown.');

            return;
        }

        $result = $queue->queue([
            'sha' => $source->sha,
            'branch' => $source->branch,
            'commit_message' => $source->commit_message,
            'commit_author' => $source->commit_author,
            'trigger' => Deployment::TRIGGER_MANUAL,
            'initiator_user_id' => auth()->id(),
        ]);

        Activity::log('deploy.redeploy', [
            'deployment' => $result['local']?->id,
            'batch' => $result['batch'],
            'sha' => $source->sha,
            'queued_for' => $result['queued'],
        ]);

        $this->flashFleetResult("Redeploy of {$source->shortSha()}", $result);
    }

    /**
     * Roll every eligible server back to its own previous release. After a
     * uniform fleet deploy that is the same SHA everywhere.
     */
    public function rollback(DeployQueue $queue): void
    {
        $result = $queue->queue([
            'trigger' => Deployment::TRIGGER_ROLLBACK,
            'initiator_user_id' => auth()->id(),
        ]);

        Activity::log('rollback.started', [
            'deployment' => $result['local']?->id,
            'batch' => $result['batch'],
            'queued_for' => $result['queued'],
        ]);

        $this->flashFleetResult('Rollback', $result);
    }

    public function render(Drift $drift)
    {
        $query = Deployment::query()->latest('id');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        $deployments = $query->paginate(30);

        /** @var Collection<int, Collection<int, Deployment>> $fleetGroups */
        $fleetGroups = $deployments->getCollection()
            ->groupBy(fn (Deployment $d) => $d->sha ?: 'pending-'.$d->id)
            ->map(function (Collection $group) {
                return $group->sortBy('server_name')->values();
            });

        $summary = $drift->summary((int) config('slipstream.fleet.heartbeat_stale_seconds', 120));

        $today = Deployment::query()
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
        $thisWeek = Deployment::query()
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();
        $lastFinished = Deployment::query()
            ->whereNotNull('duration_ms')
            ->latest('id')
            ->first();
        $lastDurationSeconds = $lastFinished?->duration_ms
            ? (int) round($lastFinished->duration_ms / 1000)
            : null;

        return view('slipstream::livewire.deployments-index', [
            'deployments' => $deployments,
            'fleetGroups' => $fleetGroups,
            'driftSummary' => $summary,
            'drift' => $drift,
            'today' => $today,
            'thisWeek' => $thisWeek,
            'lastDurationSeconds' => $lastDurationSeconds,
            'fleet' => $summary,
            'pollingMs' => (int) config('slipstream.realtime.polling_ms', 1500),
        ]);
    }
}
