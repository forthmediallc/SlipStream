<?php

namespace Slipstream\Fleet;

use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Release\DeployRef;
use Slipstream\Release\ReleaseSource;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

/**
 * What keeps a node from deploying: the fleet-wide SLIPSTREAM_PAUSED, a
 * per-node pause, or a pin. A pin is a pause that remembers why — in poll
 * mode a rollback, or a deploy of anything but what the ref names, would be
 * undone by the next poll otherwise.
 */
class NodeHold
{
    public function reason(?Server $server): ?string
    {
        if (filter_var(config('slipstream.node.paused', false), FILTER_VALIDATE_BOOLEAN)) {
            return 'paused by SLIPSTREAM_PAUSED';
        }

        if ($server === null) {
            return null;
        }

        if (! $server->enabled) {
            return 'disabled';
        }

        if ($server->isPinned()) {
            return sprintf(
                'pinned at %s (%s) since %s',
                substr((string) $server->pinned_sha, 0, 7),
                $server->pinned_reason ?: 'pinned',
                $server->pinned_at?->toDateTimeString() ?? '?'
            );
        }

        return $server->paused ? 'paused' : null;
    }

    public function pin(string $sha, string $reason): Server
    {
        $server = $this->self();
        $server->update([
            'paused' => true,
            'pinned_sha' => strtolower($sha) ?: null,
            'pinned_reason' => mb_substr($reason, 0, 255),
            'pinned_at' => now(),
        ]);

        Activity::log('node.pinned', ['sha' => $sha, 'reason' => $reason, 'server' => $server->server_name]);

        return $server;
    }

    public function resume(Server $server): void
    {
        $wasPinned = $server->isPinned();

        $server->update(['paused' => false, 'pinned_sha' => null, 'pinned_reason' => null, 'pinned_at' => null]);

        if ($wasPinned) {
            Activity::log('node.resumed', ['server' => $server->server_name]);
        }
    }

    /**
     * In poll mode, pin a node that now runs something the ref does not name,
     * so the next poll leaves it there until someone resumes it: a rollback
     * always, a manual or dashboard deploy unless it deployed the ref's own
     * commit. The poller's and bootstrap's deploys never pin.
     */
    public function afterDeploy(Deployment $deployment): void
    {
        if (! Slipstream::pollMode()) {
            return;
        }

        if ($deployment->status === Deployment::STATUS_ROLLED_BACK) {
            $this->pin((string) $deployment->sha, 'rollback');

            return;
        }

        if ($deployment->status !== Deployment::STATUS_DEPLOYED
            || in_array($deployment->trigger, [Deployment::TRIGGER_POLL, Deployment::TRIGGER_BOOTSTRAP], true)
            || ($deployment->meta['poll_ref'] ?? null) !== null) {
            return;
        }

        $seen = $this->self()->poll_seen_sha;
        if (! is_string($seen) || $seen === '') {
            try {
                $seen = app(ReleaseSource::class)->latest(DeployRef::fromConfig())->sha;
            } catch (\Throwable) {
                $seen = null;
            }
        }

        if (is_string($seen) && strtolower($seen) === strtolower((string) $deployment->sha)) {
            return;
        }

        $this->pin((string) $deployment->sha, 'manual deploy');
    }

    protected function self(): Server
    {
        return Server::query()->firstOrCreate(
            ['server_name' => Slipstream::serverName()],
            ['hostname' => gethostname() ?: null, 'enabled' => true, 'paused' => false]
        );
    }
}
