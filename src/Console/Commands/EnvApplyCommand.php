<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Environment\EnvSync;

/**
 * Apply this node's queued shared .env push. Spawned detached by an env-sync
 * nudge for near-instant apply; also the manual escape hatch (safe to run by
 * hand — it claims atomically and is idempotent).
 */
class EnvApplyCommand extends Command
{
    protected $signature = 'slipstream:env-apply';

    protected $description = 'Apply this node\'s queued shared .env push';

    public function handle(EnvSync $sync): int
    {
        $push = $sync->applyPending();

        if (! $push) {
            $this->components->info('No pending .env push for this node.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail(
            'env push '.$push->batch,
            $push->status.($push->error ? ' — '.$push->error : '')
        );

        return self::SUCCESS;
    }
}
