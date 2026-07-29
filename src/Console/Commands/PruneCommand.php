<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentEvent;
use Slipstream\Models\EnvPush;
use Slipstream\Models\WebhookDelivery;

/**
 * Keeps the DB-backed log tables bounded and clears deploys orphaned in
 * "building" by a killed process. Scheduled daily by the service provider.
 */
class PruneCommand extends Command
{
    protected $signature = 'slipstream:prune
                            {--events= : Override keep_event_days}
                            {--deliveries= : Override keep_delivery_days}';

    protected $description = 'Prune old deploy events + webhook deliveries and fail stuck in-progress deploys';

    public function handle(): int
    {
        $eventDays = (int) ($this->option('events') ?? config('slipstream.logging.keep_event_days', 30));
        if ($eventDays > 0) {
            $n = DeploymentEvent::query()->where('created_at', '<', now()->subDays($eventDays))->delete();
            $this->components->twoColumnDetail('Deploy events pruned (> '.$eventDays.'d)', (string) $n);
        }

        $deliveryDays = (int) ($this->option('deliveries') ?? config('slipstream.webhook.keep_delivery_days', 30));
        if ($deliveryDays > 0) {
            $n = WebhookDelivery::query()->where('created_at', '<', now()->subDays($deliveryDays))->delete();
            $this->components->twoColumnDetail('Webhook deliveries pruned (> '.$deliveryDays.'d)', (string) $n);
        }

        // Watchdog: a deploy killed mid-run (OOM/reboot) leaves a "building" row
        // forever. Fail anything stuck well past a plausible deploy time.
        $stuckMinutes = (int) config('slipstream.deploy.stuck_minutes', 120);
        $stuck = Deployment::query()
            ->where('status', Deployment::STATUS_BUILDING)
            ->where('started_at', '<', now()->subMinutes($stuckMinutes))
            ->get();

        foreach ($stuck as $deployment) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => "Marked failed by watchdog: stuck in 'building' for over {$stuckMinutes} minutes (deploy process likely died).",
                'finished_at' => now(),
            ]);
        }
        $this->components->twoColumnDetail('Stuck deploys failed', (string) $stuck->count());

        // Same watchdog for rows claimed but never started: a spawn that died
        // before the Deployer marked "building" leaves "pending" forever.
        // updated_at (not created_at) so a freshly claimed queued row isn't hit.
        $n = Deployment::query()
            ->where('status', Deployment::STATUS_PENDING)
            ->where('updated_at', '<', now()->subMinutes($stuckMinutes))
            ->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => "Marked failed by watchdog: stuck in 'pending' for over {$stuckMinutes} minutes (spawned process never started).",
                'finished_at' => now(),
            ]);
        $this->components->twoColumnDetail('Stuck pending deploys failed', (string) $n);

        // Queued fleet actions to a server that never came back to claim them.
        // Deploys are time-sensitive, so the window is hours, not days.
        $queuedMaxHours = (int) config('slipstream.deploy.queued_max_hours', 24);
        if ($queuedMaxHours > 0) {
            $n = Deployment::query()
                ->where('status', Deployment::STATUS_QUEUED)
                ->where('created_at', '<', now()->subHours($queuedMaxHours))
                ->update([
                    'status' => Deployment::STATUS_FAILED,
                    'failure_reason' => "Expired after {$queuedMaxHours}h queued (server offline, paused, or removed).",
                    'finished_at' => now(),
                ]);
            $this->components->twoColumnDetail('Queued fleet actions expired (> '.$queuedMaxHours.'h)', (string) $n);
        }

        $this->pruneEnvPushes();

        return self::SUCCESS;
    }

    /**
     * Env-push housekeeping (shared table; idempotent across nodes):
     * trim terminal rows, expire pushes to servers that never returned, and
     * fail applies whose process died.
     */
    protected function pruneEnvPushes(): void
    {
        if (! $this->envPushTableReady()) {
            return;
        }

        $keepDays = (int) config('slipstream.environment.keep_push_days', 30);
        if ($keepDays > 0) {
            $n = EnvPush::query()
                ->whereIn('status', EnvPush::TERMINAL)
                ->where('updated_at', '<', now()->subDays($keepDays))
                ->delete();
            $this->components->twoColumnDetail('Env pushes pruned (> '.$keepDays.'d)', (string) $n);
        }

        $pendingDays = (int) config('slipstream.environment.pending_max_days', 14);
        if ($pendingDays > 0) {
            $n = EnvPush::query()
                ->where('status', EnvPush::STATUS_PENDING)
                ->where('created_at', '<', now()->subDays($pendingDays))
                ->update([
                    'status' => EnvPush::STATUS_CANCELLED,
                    'error' => "Expired after {$pendingDays}d pending (target offline or removed).",
                ]);
            $this->components->twoColumnDetail('Env pushes expired (> '.$pendingDays.'d pending)', (string) $n);
        }

        // Watchdog: an apply process killed mid-run leaves an "applying" row.
        $n = EnvPush::query()
            ->where('status', EnvPush::STATUS_APPLYING)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update([
                'status' => EnvPush::STATUS_FAILED,
                'error' => 'Apply process died (watchdog).',
            ]);
        $this->components->twoColumnDetail('Env pushes failed (stuck applying)', (string) $n);
    }

    protected function envPushTableReady(): bool
    {
        try {
            return Schema::hasTable('slipstream_env_pushes');
        } catch (\Throwable) {
            return false;
        }
    }
}
