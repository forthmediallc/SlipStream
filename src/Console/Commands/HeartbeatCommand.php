<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Database\Tables;
use Slipstream\Deploy\StuckDeploymentsSweeper;
use Slipstream\Environment\EnvSync;
use Slipstream\Fleet\DeployQueue;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Fleet\Poller;
use Slipstream\Slipstream;
use Slipstream\Webhooks\WebhookSourceRanges;

class HeartbeatCommand extends Command
{
    protected $signature = 'slipstream:heartbeat';

    protected $description = 'Register / refresh this node in the Slipstream fleet registry';

    public function handle(Heartbeat $heartbeat, EnvSync $sync, DeployQueue $queue, StuckDeploymentsSweeper $sweeper, Tables $tables, Poller $poller, WebhookSourceRanges $ranges): int
    {
        // A node with its own Slipstream database keeps that schema current
        // itself: the app's migrations table is shared, and says a migration
        // ran as soon as any node ran it.
        try {
            if ($tables->ensureCurrentIfOwnConnection()) {
                $this->components->twoColumnDetail('slipstream schema', 'brought current');
            }
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('slipstream schema', 'error: '.$e->getMessage());
        }

        $server = $heartbeat->pulse();

        $this->components->twoColumnDetail(
            $server->server_name,
            ($server->current_sha ? substr($server->current_sha, 0, 7) : 'no-sha')
            .' · '.$server->last_heartbeat_at?->toDateTimeString()
        );

        // The git hosts' hook ranges behind the allow-list keywords, when one
        // is due. Webhook mode only: poll mode has no hook to restrict.
        if (! Slipstream::pollMode()) {
            try {
                foreach ($ranges->refreshIfDue() as $result) {
                    $this->components->twoColumnDetail('webhook ranges '.$result['keyword'], $result['detail']);
                }
            } catch (\Throwable $e) {
                $this->components->twoColumnDetail('webhook ranges', 'error: '.$e->getMessage());
            }
        }

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

        // Deploys orphaned by a killed process used to sit in "building" on the
        // dashboard until the daily prune; sweeping here makes the dashboard
        // honest within the minute. Scoped to THIS node's rows so its
        // stuck_minutes only ever judges its own deploys — the daily prune
        // stays fleet-wide as the backstop for nodes that died outright.
        try {
            $swept = $sweeper->sweep(Slipstream::serverName());
            foreach (array_filter($swept) as $bucket => $count) {
                $this->components->twoColumnDetail('stuck '.$bucket.' deploys failed', (string) $count);
            }
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('stuck deploy sweep', 'error: '.$e->getMessage());
        }

        // Poll mode: look at the deploy ref and converge on it. Last, so the
        // sweep above has already failed anything that died.
        try {
            $tick = $poller->tick();
            if ($tick !== null) {
                $this->components->twoColumnDetail('poll', $tick['result'].' — '.$tick['detail']);
            }
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('poll', 'error: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
