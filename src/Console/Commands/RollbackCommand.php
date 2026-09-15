<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Deploy\EventLogger;
use Slipstream\Deploy\Rollbacker;
use Slipstream\Models\Deployment;
use Slipstream\Slipstream;

class RollbackCommand extends Command
{
    protected $signature = 'slipstream:rollback
                            {--deployment= : Existing deployment id for this rollback record}';

    protected $description = 'Roll back to the previous release (code only — does not un-migrate)';

    public function handle(): int
    {
        $this->warn('Rollback repoints CODE only. It cannot un-migrate the database.');

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
