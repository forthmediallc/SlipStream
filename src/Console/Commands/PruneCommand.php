<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Deploy\StuckDeploymentsSweeper;
use Slipstream\Models\DeploymentEvent;
use Slipstream\Models\EnvPush;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Slipstream;

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

    public function handle(StuckDeploymentsSweeper $sweeper): int
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

        // Long-horizon backstop for deploys orphaned by a killed process. The
        // heartbeat runs the same sweep every minute, so by the time prune runs
        // these counts are usually zero.
        $swept = $sweeper->sweep();
        $this->components->twoColumnDetail('Stuck deploys failed', (string) $swept['building']);
        $this->components->twoColumnDetail('Stuck pending deploys failed', (string) $swept['pending']);

        $queuedMaxHours = (int) config('slipstream.deploy.queued_max_hours', 24);
        if ($queuedMaxHours > 0) {
            $this->components->twoColumnDetail(
                'Queued fleet actions expired (> '.$queuedMaxHours.'h)',
                (string) $swept['queued']
            );
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
            return Slipstream::schema()->hasTable('slipstream_env_pushes');
        } catch (\Throwable) {
            return false;
        }
    }
}
