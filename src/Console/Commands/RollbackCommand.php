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
        $rollbacker = new Rollbacker($deployment, $logger);

        try {
            $rollbacker->run();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $deployment->refresh();
        $this->info('Rollback complete: '.$deployment->shortSha());

        return self::SUCCESS;
    }
}
