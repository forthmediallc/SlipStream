<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Deploy\EventLogger;
use Slipstream\Deploy\Rollbacker;
use Slipstream\Fleet\DeployQueue;
use Slipstream\Models\Deployment;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

class RollbackCommand extends Command
{
    protected $signature = 'slipstream:rollback
                            {--deployment= : Existing deployment id for this rollback record}
                            {--fleet : Roll every enabled, unpaused server back to its own previous release — this node detached, peers claim their row}';

    protected $description = 'Roll back to the previous release (code only — does not un-migrate)';

    public function handle(): int
    {
        $this->warn('Rollback repoints CODE only. It cannot un-migrate the database.');

        if ($this->option('fleet')) {
            return $this->rollbackFleet(app(DeployQueue::class));
        }

        if ($id = $this->option('deployment')) {
            $deployment = Deployment::query()->findOrFail($id);
        } else {
            $deployment = Deployment::query()->create([
                'server_name' => Slipstream::serverName(),
                'trigger' => Deployment::TRIGGER_ROLLBACK,
                'status' => Deployment::STATUS_PENDING,
            ]);
        }

        $logger = new EventLogger($deployment);

        try {
            // Construction resolves the PHP binary and can throw — see
            // DeployCommand for why that belongs inside the try.
            $rollbacker = new Rollbacker($deployment, $logger);
            $rollbacker->run();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->recordUnrecordedFailure($deployment, $logger, $e);

            return self::FAILURE;
        }

        $deployment->refresh();
        $this->info('Rollback complete: '.$deployment->shortSha());

        return self::SUCCESS;
    }

    /**
     * The dashboard's fleet-wide Rollback from the shell. Each server repoints
     * at ITS OWN previous release; a PCI node never drives peers.
     */
    protected function rollbackFleet(DeployQueue $queue): int
    {
        if (Slipstream::pciMode()) {
            $this->components->error('PCI mode: this node does not drive peers. Run without --fleet to roll back this server.');

            return self::FAILURE;
        }

        $result = $queue->queue(['trigger' => Deployment::TRIGGER_ROLLBACK]);
        $local = $result['local'];

        Activity::log('rollback.started', [
            'via' => 'cli',
            'deployment' => $local?->id,
            'batch' => $result['batch'],
            'queued_for' => $result['queued'],
        ]);

        if (! $local && $result['queued'] === []) {
            $this->components->error('Nothing started: no eligible servers'.($result['skipped'] !== [] ? ' (paused/disabled: '.implode(', ', $result['skipped']).')' : '.'));

            return self::FAILURE;
        }

        if ($local) {
            $this->components->twoColumnDetail(
                Slipstream::serverName(),
                $local->status === Deployment::STATUS_FAILED
                    ? '<fg=red>#'.$local->id.' failed to start: '.($local->failure_reason ?: 'spawn failed').'</>'
                    : "#{$local->id} rollback started (detached)"
            );
        }
        if ($result['queued'] !== []) {
            $this->components->twoColumnDetail('Queued for', implode(', ', $result['queued']));
        }
        if ($result['skipped'] !== []) {
            $this->components->twoColumnDetail('Skipped (paused/disabled)', implode(', ', $result['skipped']));
        }
        if ($local) {
            $this->line("  Follow it: php artisan slipstream:deployment {$local->id} --follow");
        }

        return $local && $local->status === Deployment::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Rollbacker::run() records its own failures; one thrown before it started
     * has not been recorded and would sit in "pending" until the watchdog.
     */
    protected function recordUnrecordedFailure(Deployment $deployment, EventLogger $logger, \Throwable $e): void
    {
        $deployment->refresh();

        if ($deployment->isFinished() || $deployment->status === Deployment::STATUS_SUPERSEDED) {
            return;
        }

        $logger->error('ROLLBACK FAILED: '.$e->getMessage());
        $deployment->update([
            'status' => Deployment::STATUS_FAILED,
            'failure_reason' => $e->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
