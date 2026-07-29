<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Environment\EnvSync;
use Slipstream\Fleet\DeployQueue;
use Slipstream\Fleet\Heartbeat;

class HeartbeatCommand extends Command
{
    protected $signature = 'slipstream:heartbeat';

    protected $description = 'Register / refresh this node in the Slipstream fleet registry';

    public function handle(Heartbeat $heartbeat, EnvSync $sync, DeployQueue $queue): int
    {
        $server = $heartbeat->pulse();

        $this->components->twoColumnDetail(
            $server->server_name,
            ($server->current_sha ? substr($server->current_sha, 0, 7) : 'no-sha')
            .' · '.$server->last_heartbeat_at?->toDateTimeString()
        );

        // Apply any .env push queued for this node (the reliable backstop for
        // the instant nudge). Never let it fail the heartbeat.
        try {
            $push = $sync->applyPending();
            if ($push) {
                $this->components->twoColumnDetail('env push '.$push->batch, $push->status);
            }
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('env push', 'error: '.$e->getMessage());
        }

        // Same backstop for queued fleet deploys/rollbacks: claim this node's
        // newest queued row and spawn it if the instant nudge never arrived.
        try {
            $claimed = $queue->processQueued();
            if ($claimed) {
                $this->components->twoColumnDetail('fleet action #'.$claimed->id, $claimed->trigger.' spawned');
            }
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('fleet action', 'error: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
