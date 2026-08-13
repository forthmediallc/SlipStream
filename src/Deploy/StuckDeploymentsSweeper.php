<?php

namespace Slipstream\Deploy;

use Slipstream\Models\Deployment;

/**
 * Fails deploy rows whose process died without ever reaching a terminal status.
 *
 * Run from both the every-minute heartbeat and the daily prune. The heartbeat
 * is what keeps the dashboard honest: the thresholds below are unchanged, but a
 * row now flips within a minute of crossing one instead of waiting up to a day
 * for the next prune.
 *
 * A deploy interrupted at the keyboard is handled earlier and more precisely by
 * DeployCommand's signal trap; this is the backstop for kills that leave no
 * chance to run code (SIGKILL, OOM, reboot).
 */
class StuckDeploymentsSweeper
{
    /**
     * @return array{building: int, pending: int, queued: int}
     */
    public function sweep(): array
    {
        $stuckMinutes = (int) config('slipstream.deploy.stuck_minutes', 120);

        $building = 0;
        foreach ($this->stuckBuilding($stuckMinutes) as $deployment) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => "Marked failed by watchdog: stuck in 'building' for over {$stuckMinutes} minutes (deploy process likely died).",
                'finished_at' => now(),
            ]);
            $building++;
        }

        // Rows claimed but never started: a spawn that died before the Deployer
        // marked "building" leaves "pending" forever. updated_at (not
        // created_at) so a freshly claimed queued row isn't hit.
        $pending = Deployment::query()
            ->where('status', Deployment::STATUS_PENDING)
            ->where('updated_at', '<', now()->subMinutes($stuckMinutes))
            ->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => "Marked failed by watchdog: stuck in 'pending' for over {$stuckMinutes} minutes (spawned process never started).",
                'finished_at' => now(),
            ]);

        // Queued fleet actions to a server that never came back to claim them.
        // Deploys are time-sensitive, so the window is hours, not days.
        $queued = 0;
        $queuedMaxHours = (int) config('slipstream.deploy.queued_max_hours', 24);
        if ($queuedMaxHours > 0) {
            $queued = Deployment::query()
                ->where('status', Deployment::STATUS_QUEUED)
                ->where('created_at', '<', now()->subHours($queuedMaxHours))
                ->update([
                    'status' => Deployment::STATUS_FAILED,
                    'failure_reason' => "Expired after {$queuedMaxHours}h queued (server offline, paused, or removed).",
                    'finished_at' => now(),
                ]);
        }

        return ['building' => $building, 'pending' => (int) $pending, 'queued' => (int) $queued];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Deployment>
     */
    protected function stuckBuilding(int $stuckMinutes)
    {
        return Deployment::query()
            ->where('status', Deployment::STATUS_BUILDING)
            ->where('started_at', '<', now()->subMinutes($stuckMinutes))
            ->get();
    }
}
