<?php

namespace Slipstream\Livewire;

use Illuminate\Support\Collection;
use Livewire\WithPagination;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Fleet\Drift;
use Slipstream\Models\Deployment;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

class DeploymentsIndex extends SlipstreamComponent
{
    use WithPagination;

    public string $statusFilter = '';

    public string $viewMode = 'list'; // list | fleet

    public function deployNow(SettingsRepository $settings, ProcessDetacher $detacher): void
    {
        $deployment = Deployment::query()->create([
            'server_name' => Slipstream::serverName(),
            'branch' => $settings->branch(),
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_PENDING,
            'initiator_user_id' => auth()->id(),
        ]);

        try {
            $detacher->spawnArtisan([
                'slipstream:deploy',
                '--deployment='.$deployment->id,
            ]);
            Activity::log('deploy.started', ['deployment' => $deployment->id]);
            session()->flash('slipstream_status', "Deploy #{$deployment->id} started.");
        } catch (\Throwable $e) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            session()->flash('slipstream_error', 'Failed to start deploy: '.$e->getMessage());
        }
    }

    /**
     * Redeploy a specific past release by its SHA (fresh deployment record).
     */
    public function redeploy(int $deploymentId, ProcessDetacher $detacher): void
    {
        $source = Deployment::query()->find($deploymentId);
        if (! $source || ! $source->sha) {
            session()->flash('slipstream_error', 'Cannot redeploy: original SHA unknown.');

            return;
        }

        $deployment = Deployment::query()->create([
            'server_name' => Slipstream::serverName(),
            'sha' => $source->sha,
            'branch' => $source->branch,
            'commit_message' => $source->commit_message,
            'commit_author' => $source->commit_author,
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_PENDING,
            'initiator_user_id' => auth()->id(),
        ]);

        try {
            $detacher->spawnArtisan([
                'slipstream:deploy',
                '--deployment='.$deployment->id,
            ]);
            Activity::log('deploy.redeploy', ['deployment' => $deployment->id, 'sha' => $source->sha]);
            session()->flash('slipstream_status', "Redeploy #{$deployment->id} started ({$source->shortSha()}).");
        } catch (\Throwable $e) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            session()->flash('slipstream_error', 'Failed to start redeploy: '.$e->getMessage());
        }
    }

    public function rollback(ProcessDetacher $detacher): void
    {
        $deployment = Deployment::query()->create([
            'server_name' => Slipstream::serverName(),
            'trigger' => Deployment::TRIGGER_ROLLBACK,
            'status' => Deployment::STATUS_PENDING,
            'initiator_user_id' => auth()->id(),
        ]);

        try {
            $detacher->spawnArtisan([
                'slipstream:rollback',
                '--deployment='.$deployment->id,
            ]);
            Activity::log('rollback.started', ['deployment' => $deployment->id]);
            session()->flash(
                'slipstream_status',
                "Rollback #{$deployment->id} started. Note: code only — does not un-migrate."
            );
        } catch (\Throwable $e) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            session()->flash('slipstream_error', 'Failed to start rollback: '.$e->getMessage());
        }
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
