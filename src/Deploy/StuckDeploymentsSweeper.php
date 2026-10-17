<?php

namespace Slipstream\Deploy;

use Illuminate\Database\Eloquent\Builder;
use Slipstream\Models\Deployment;
use Slipstream\Support\AuditLog;

/**
 * Fails deploy rows whose process died without ever reaching a terminal status.
 *
 * Run from two places with two scopes. The every-minute heartbeat sweeps only
 * THIS server's rows — each node applies its own stuck_minutes to deploys it
 * has authority over, so one node's threshold is never applied to another
 * node's still-running deploy, and N nodes don't race to issue identical
 * fleet-wide updates every minute. The daily prune sweeps unscoped, as the
 * backstop for a node that died and will never sweep its own rows again.
 *
 * A deploy interrupted at the keyboard is handled earlier and more precisely by
 * DeployCommand's signal trap; this is the backstop for kills that leave no
 * chance to run code (SIGKILL, OOM, reboot).
 */
class StuckDeploymentsSweeper
{
    /**
     * In-code fallback for pending_stuck_minutes: a published config from
     * before the key existed has no entry for it (config merging is shallow).
     */
    public const DEFAULT_PENDING_MINUTES = 5;

    /**
     * @param  string|null  $serverName  sweep only this server's rows; null = fleet-wide
     * @return array{building: int, pending: int, queued: int}
     */
    public function sweep(?string $serverName = null): array
    {
        $building = 0;
        $pending = 0;

        // 0 disables the watchdog, matching every neighbouring retention key
        // (queued_max_hours, keep_event_days, …). Without the guard, a zero
        // threshold matches every in-flight row the moment it starts.
        $stuckMinutes = (int) config('slipstream.deploy.stuck_minutes', 120);
        if ($stuckMinutes > 0) {
            $building = $this->fail(
                $this->scoped($serverName)
                    ->where('status', Deployment::STATUS_BUILDING)
                    ->where('started_at', '<', now()->subMinutes($stuckMinutes)),
                "Marked failed by watchdog: stuck in 'building' for over {$stuckMinutes} minutes (deploy process likely died)."
            );

            // Rows claimed but never started. "pending" only covers process
            // start-up — the Deployer marks "building" right after taking the
            // lock — so it gets its own, much shorter window: the cause is a
            // spawn that never ran artisan (the FPM binary as interpreter, a
            // wrong SLIPSTREAM_PHP, artisan missing), and two hours is a long
            // time to show "pending" for something that died in its first
            // second. Never longer than stuck_minutes; 0 falls back to it.
            // updated_at (not created_at) so a freshly claimed queued row
            // isn't hit.
            $pendingMinutes = (int) config('slipstream.deploy.pending_stuck_minutes', self::DEFAULT_PENDING_MINUTES);
            $pendingMinutes = $pendingMinutes > 0 ? min($pendingMinutes, $stuckMinutes) : $stuckMinutes;

            $pending = $this->fail(
                $this->scoped($serverName)
                    ->where('status', Deployment::STATUS_PENDING)
                    ->where('updated_at', '<', now()->subMinutes($pendingMinutes)),
                "Marked failed by watchdog: still 'pending' after {$pendingMinutes} minutes — the spawned deploy process never started. Check deploy/logs/detach-*.log and run php artisan slipstream:doctor."
            );
        }

        // Queued fleet actions to a server that never came back to claim them.
        // Deploys are time-sensitive, so the window is hours, not days.
        $queued = 0;
        $queuedMaxHours = (int) config('slipstream.deploy.queued_max_hours', 24);
        if ($queuedMaxHours > 0) {
            $queued = $this->fail(
                $this->scoped($serverName)
                    ->where('status', Deployment::STATUS_QUEUED)
                    ->where('created_at', '<', now()->subHours($queuedMaxHours)),
                "Expired after {$queuedMaxHours}h queued (server offline, paused, or removed)."
            );
        }

        return ['building' => $building, 'pending' => $pending, 'queued' => $queued];
    }

    /**
     * Fail every row the query matches, in one UPDATE that keeps the query's
     * own status condition (a row that finished between a look and the write
     * must not be overwritten). A query-builder update fires no model events,
     * so with the audit log on the rows are read back and recorded here.
     *
     * @param  Builder<Deployment>  $query
     */
    protected function fail(Builder $query, string $reason): int
    {
        $update = [
            'status' => Deployment::STATUS_FAILED,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ];

        if (! AuditLog::enabled()) {
            return (int) $query->update($update);
        }

        $ids = (clone $query)->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        $count = (int) (clone $query)->whereIn('id', $ids)->update($update);

        $failed = Deployment::query()
            ->whereIn('id', $ids)
            ->where('status', Deployment::STATUS_FAILED)
            ->where('failure_reason', $reason)
            ->pluck('id');

        foreach ($failed as $id) {
            AuditLog::record('deployment.failed', ['deployment' => $id, 'reason' => $reason, 'via' => 'watchdog']);
        }

        return $count;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Deployment>
     */
    protected function scoped(?string $serverName)
    {
        return Deployment::query()
            ->when($serverName !== null, fn ($query) => $query->where('server_name', $serverName));
    }
}
