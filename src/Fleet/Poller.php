<?php

namespace Slipstream\Fleet;

use Carbon\CarbonInterface;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Release\DeployRef;
use Slipstream\Release\ReleaseRef;
use Slipstream\Release\ReleaseSource;
use Slipstream\Slipstream;
use Slipstream\Support\AuditLog;

/**
 * Poll mode: once per interval, from the minute heartbeat, look at the deploy
 * ref and converge on it. Nothing reaches the node from outside; it decides
 * by itself, so every instance of an Auto Scaling group deploys a push, not
 * just the one the load balancer handed a webhook.
 */
class Poller
{
    public const UP_TO_DATE = 'up-to-date';

    public const DEPLOYING = 'deploying';

    public const HELD = 'held';

    public const BUSY = 'busy';

    public const BACKOFF = 'backoff';

    public const ERROR = 'error';

    public function __construct(
        protected ReleaseSource $source,
        protected ProcessDetacher $detacher,
        protected NodeHold $hold,
        protected Heartbeat $heartbeat,
    ) {}

    /**
     * One check, if one is due (or forced). Returns what happened, or null
     * when this node is not in poll mode or the next check is later.
     *
     * @return array{result: string, detail: string, deployment: ?Deployment}|null
     */
    public function tick(bool $force = false): ?array
    {
        if (! Slipstream::pollMode()) {
            return null;
        }

        $server = $this->server();
        if (! $force && $server->poll_next_at !== null && $server->poll_next_at->isFuture()) {
            return null;
        }

        if (! $force) {
            $this->jitter();
        }

        try {
            $ref = DeployRef::fromConfig();
            $release = $this->source->latest($ref);
        } catch (\Throwable $e) {
            return $this->record($server, self::ERROR, $e->getMessage());
        }

        $running = $this->heartbeat->readCurrentSha(Slipstream::appRoot());

        if (is_string($running) && strtolower($running) === $release->sha) {
            return $this->record($server, self::UP_TO_DATE, 'running '.$release->short().' — '.$release->name, $release);
        }

        if (($reason = $this->hold->reason($server)) !== null) {
            return $this->record($server, self::HELD, $reason.'; '.$release->name.' is at '.$release->short(), $release);
        }

        if (($inFlight = $this->inFlight()) !== null) {
            return $this->record($server, self::BUSY, "deployment #{$inFlight->id} is {$inFlight->status}", $release);
        }

        if (($backoff = $this->backoff($release->sha)) !== null) {
            return $this->record(
                $server,
                self::BACKOFF,
                sprintf('%s failed %d× here — next try %s, or sooner if the ref moves', $release->short(), $backoff['failures'], $backoff['until']->toDateTimeString()),
                $release
            );
        }

        return $this->deploy($server, $ref, $release);
    }

    /**
     * When a failed commit may be tried again: after retry_minutes, doubling
     * per failure up to retry_max_minutes. Only failures since this node's
     * last successful deploy count, so a commit that once failed and later
     * deployed starts clean. Null when it may be tried now.
     *
     * @return array{failures: int, until: CarbonInterface}|null
     */
    public function backoff(string $sha): ?array
    {
        $self = Slipstream::serverName();
        $lastSuccess = (int) Deployment::query()
            ->where('server_name', $self)
            ->where('status', Deployment::STATUS_DEPLOYED)
            ->max('id');

        $failures = Deployment::query()
            ->where('server_name', $self)
            ->where('id', '>', $lastSuccess)
            ->where('status', Deployment::STATUS_FAILED)
            ->whereRaw('lower(sha) = ?', [strtolower($sha)])
            ->orderByDesc('id')
            ->get(['id', 'finished_at', 'updated_at']);

        if ($failures->isEmpty()) {
            return null;
        }

        $base = max(1, (int) config('slipstream.trigger.retry_minutes', 5));
        $cap = max($base, (int) config('slipstream.trigger.retry_max_minutes', 240));
        $minutes = min($cap, $base * (2 ** min(20, $failures->count() - 1)));

        $last = $failures->first();
        $until = ($last->finished_at ?? $last->updated_at)->copy()->addMinutes($minutes);

        return $until->isFuture() ? ['failures' => $failures->count(), 'until' => $until] : null;
    }

    protected function deploy(Server $server, DeployRef $ref, ReleaseRef $release): array
    {
        $deployment = Deployment::query()->create([
            'server_name' => Slipstream::serverName(),
            'sha' => $release->sha,
            'branch' => $release->name,
            'trigger' => Deployment::TRIGGER_POLL,
            'status' => Deployment::STATUS_PENDING,
            'meta' => array_filter([
                'deploy_from' => $release->isTag ? 'tag' : null,
                'poll_ref' => $ref->key(),
            ]),
        ]);

        try {
            $this->detacher->spawnArtisan(['slipstream:deploy', '--deployment='.$deployment->id]);
        } catch (\Throwable $e) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => 'Spawn failed: '.$e->getMessage(),
                'finished_at' => now(),
            ]);

            return $this->record($server, self::ERROR, 'could not start the deploy: '.$e->getMessage(), $release, $deployment);
        }

        AuditLog::record('poll.deploy_started', [
            'deployment' => $deployment->id,
            'sha' => $release->sha,
            'ref' => $release->ref(),
        ]);

        return $this->record($server, self::DEPLOYING, "#{$deployment->id} → {$release->short()} ({$release->name})", $release, $deployment);
    }

    /**
     * Persist the check and schedule the next one. Unchanged results are not
     * re-audited every minute; a change is.
     *
     * @return array{result: string, detail: string, deployment: ?Deployment}
     */
    protected function record(Server $server, string $result, string $detail, ?ReleaseRef $release = null, ?Deployment $deployment = null): array
    {
        $changed = $server->poll_result !== $result || ($release && $server->poll_seen_sha !== $release->sha);

        $server->update(array_filter([
            'poll_checked_at' => now(),
            'poll_next_at' => $this->nextCheck(),
            'poll_result' => $result,
            'poll_detail' => mb_substr($detail, 0, 1000),
            'poll_seen_sha' => $release?->sha,
            'poll_seen_ref' => $release?->ref(),
        ], fn ($value) => $value !== null));

        if ($changed && $result !== self::DEPLOYING) {
            AuditLog::record('poll.'.$result, ['detail' => $detail, 'sha' => $release?->sha, 'ref' => $release?->ref()]);
        }

        return ['result' => $result, 'detail' => $detail, 'deployment' => $deployment];
    }

    /**
     * Truncated to the minute so the next heartbeat after the interval always
     * finds the check due, however long this one's jitter and work took.
     */
    protected function nextCheck(): CarbonInterface
    {
        return now()->addSeconds(max(60, (int) config('slipstream.trigger.poll_interval', 60)))->startOfMinute();
    }

    /**
     * Spread a fleet's checks across the minute — every node's cron fires at
     * :00. Capped well inside the minute the heartbeat has.
     */
    protected function jitter(): void
    {
        $max = min(45, max(0, (int) config('slipstream.trigger.poll_jitter', 20)));

        if ($max > 0) {
            sleep(random_int(0, $max));
        }
    }

    protected function inFlight(): ?Deployment
    {
        return Deployment::query()
            ->where('server_name', Slipstream::serverName())
            ->whereIn('status', [Deployment::STATUS_PENDING, Deployment::STATUS_BUILDING])
            ->latest('id')
            ->first();
    }

    protected function server(): Server
    {
        return Server::query()->firstOrCreate(
            ['server_name' => Slipstream::serverName()],
            ['hostname' => gethostname() ?: null, 'enabled' => true, 'paused' => false]
        );
    }
}
