<?php

namespace Slipstream\Fleet;

use Carbon\CarbonInterface;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Release\ArtifactSource;
use Slipstream\Release\Core\ArtifactRefused;
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

    /** Artifact mode: the pointer in the store failed verification. */
    public const REFUSED = 'refused';

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
        } catch (ArtifactRefused $e) {
            // A pointer the node will not follow is not an error to retry
            // harder: no deployment row, no back-off, and the node keeps
            // serving. The source already audited why.
            return $this->record($server, self::REFUSED, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->record($server, self::ERROR, $e->getMessage());
        }

        // What current serves, never the checkout this process runs from.
        $running = $this->heartbeat->liveSha(Slipstream::appRoot());

        if ($running === $release->sha) {
            return $this->record($server, self::UP_TO_DATE, 'running '.$release->short().' — '.$release->name, $release);
        }

        if (($reason = $this->hold->reason($server)) !== null) {
            return $this->record($server, self::HELD, $reason.'; '.$release->name.' is at '.$release->short(), $release);
        }

        if (($inFlight = $this->inFlight()) !== null) {
            return $this->record($server, self::BUSY, "deployment #{$inFlight->id} is {$inFlight->status}", $release);
        }

        if (($backoff = $this->backoff($release->sha, $this->manifestOf($ref))) !== null) {
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
     * In artifact mode a failure belongs to the release it was, not only to
     * the commit: a republished build of the same commit (a new manifest)
     * is tried at once.
     *
     * @return array{failures: int, until: CarbonInterface}|null
     */
    public function backoff(string $sha, ?string $manifestSha256 = null): ?array
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
            ->get(['id', 'finished_at', 'updated_at', 'meta']);

        if ($manifestSha256 !== null) {
            $failures = $failures->filter(function (Deployment $failed) use ($manifestSha256) {
                // A deploy refused during the fetch never got meta.artifact;
                // the pointer it was started from says which build it was.
                // One that says neither (a refused `--sha` deploy) is not
                // evidence against the build the pointer names.
                $failedManifest = $failed->meta['artifact']['manifest_sha256'] ?? $failed->meta['pointer']['manifest_sha256'] ?? null;

                return $failedManifest === $manifestSha256;
            })->values();
        }

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
                // The pointer this check followed: the deploy fetches the
                // manifest it named even if the pointer moves meanwhile.
                'pointer' => $this->pointerOf($ref),
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
        $changed = $server->poll_result !== $result
            || ($release && $server->poll_seen_sha !== $release->sha)
            // Each distinct bad pointer gets its own line; an unchanged one does not repeat every minute.
            || ($result === self::REFUSED && $server->poll_detail !== mb_substr($detail, 0, 1000));

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

    /**
     * In artifact mode, the manifest the ref's pointer names now.
     */
    protected function manifestOf(DeployRef $ref): ?string
    {
        return $this->pointerOf($ref)['manifest_sha256'] ?? null;
    }

    /**
     * @return array{sequence: int, manifest_sha256: string}|null
     */
    protected function pointerOf(DeployRef $ref): ?array
    {
        if (! $this->source instanceof ArtifactSource) {
            return null;
        }

        try {
            $pointer = $this->source->pointer($ref);

            return ['sequence' => $pointer->sequence, 'manifest_sha256' => $pointer->manifestSha256];
        } catch (\Throwable) {
            return null;
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
