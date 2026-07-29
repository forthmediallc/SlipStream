<?php

namespace Slipstream\Fleet;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

/**
 * Fleet-wide dashboard actions (deploy / redeploy / rollback) without an
 * orchestrator, on the same pattern as env pushes: the shared DB is the source
 * of truth, an instant signed nudge makes peers pick their row up in seconds,
 * and each node's minute heartbeat is the reliable backstop.
 *
 * The initiating node runs its own deployment immediately (unchanged UX); every
 * other eligible server gets a `queued` deployment row that IT claims and runs
 * locally — no SSH, no cross-server process control.
 */
class DeployQueue
{
    /**
     * Domain-separation tag for the content-free "check your deploy queue"
     * nudge — its own channel, so a captured env-sync or relay signature can
     * never be replayed here (and vice versa).
     */
    public const SIGNING_VERSION = 'slipstream.deploysync.v1';

    public function __construct(
        protected ProcessDetacher $detacher,
    ) {}

    /**
     * Self-register like the webhook path does, so a fresh node's dashboard
     * can fleet-deploy before its first heartbeat ever ran.
     */
    public function ensureSelfRegistered(): void
    {
        Server::query()->firstOrCreate(
            ['server_name' => Slipstream::serverName()],
            ['hostname' => gethostname() ?: null, 'enabled' => true, 'paused' => false]
        );
    }

    /**
     * Servers eligible for a fleet build: enabled and not paused. Pause keeps
     * its documented meaning — builds skipped — for manual actions too, not
     * just webhooks.
     *
     * @return Collection<int, Server>
     */
    public function targets(): Collection
    {
        return Server::query()
            ->where('enabled', true)
            ->where('paused', false)
            ->orderBy('server_name')
            ->get();
    }

    /**
     * Create one deployment per target server from a shared attribute set.
     * This node: `pending` + spawned immediately. Peers: `queued` (their
     * heartbeat/nudge claims it). Null targets = the whole eligible fleet.
     *
     * @param  array<string, mixed>  $attributes  Deployment attributes (trigger, branch, sha, meta, …)
     * @param  list<string>|null  $targetNames
     * @return array{batch: string, local: ?Deployment, queued: list<string>, skipped: list<string>}
     */
    public function queue(array $attributes, ?array $targetNames = null): array
    {
        $this->ensureSelfRegistered();

        $eligible = $this->targets();
        $self = Slipstream::serverName();
        $batch = (string) Str::uuid();

        $names = $targetNames === null
            ? $eligible->pluck('server_name')->all()
            : array_values(array_unique(array_filter(array_map('trim', $targetNames))));

        // Anything explicitly asked for that isn't eligible is reported back,
        // not silently dropped.
        $eligibleNames = $eligible->pluck('server_name')->all();
        $skipped = array_values(array_diff($names, $eligibleNames));
        $names = array_values(array_intersect($names, $eligibleNames));

        $meta = array_merge($attributes['meta'] ?? [], [
            'fleet_batch' => $batch,
            'queued_from' => $self,
        ]);

        $local = null;
        $queued = [];

        foreach ($names as $target) {
            $row = array_merge($attributes, [
                'server_name' => $target,
                'meta' => $meta,
                'status' => $target === $self ? Deployment::STATUS_PENDING : Deployment::STATUS_QUEUED,
            ]);

            if ($target === $self) {
                $local = Deployment::query()->create($row);

                try {
                    $this->spawn($local);
                } catch (\Throwable $e) {
                    // The local run fails loudly (like deployNow always has) but
                    // must not stop the peers from being queued.
                    $local->update([
                        'status' => Deployment::STATUS_FAILED,
                        'failure_reason' => 'Spawn failed: '.$e->getMessage(),
                        'finished_at' => now(),
                    ]);
                }

                continue;
            }

            // Last action wins: an older queued deploy/rollback for this server
            // is moot once a newer one is queued.
            Deployment::query()
                ->where('server_name', $target)
                ->where('status', Deployment::STATUS_QUEUED)
                ->update([
                    'status' => Deployment::STATUS_SUPERSEDED,
                    'failure_reason' => 'Superseded by a newer queued fleet action.',
                    'finished_at' => now(),
                ]);

            Deployment::query()->create($row);
            $queued[] = $target;
        }

        if ($queued !== []) {
            // Instant nudge to peers with a base_url; a spawn failure is
            // harmless because each node's heartbeat claims its row within a
            // minute.
            try {
                $this->detacher->spawnArtisan(['slipstream:deploy-nudge', '--batch='.$batch]);
            } catch (\Throwable $e) {
                Log::warning('Slipstream deploy-nudge spawn failed: '.$e->getMessage());
            }
        }

        return ['batch' => $batch, 'local' => $local, 'queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Claim and run this node's newest queued fleet action. Called by the
     * minute heartbeat and by the signed deploy-sync nudge; the queued→pending
     * claim is atomic, so the two never double-spawn one row.
     */
    public function processQueued(): ?Deployment
    {
        $self = Slipstream::serverName();

        // Re-check eligibility at claim time: a row queued just before the
        // server was paused/disabled stays queued (resume catches up; prune
        // expires it if that never happens).
        $server = Server::query()->where('server_name', $self)->first();
        if ($server && (! $server->enabled || $server->paused)) {
            return null;
        }

        $next = Deployment::query()
            ->where('server_name', $self)
            ->where('status', Deployment::STATUS_QUEUED)
            ->latest('id')
            ->first();

        if (! $next) {
            return null;
        }

        $claimed = Deployment::query()
            ->whereKey($next->getKey())
            ->where('status', Deployment::STATUS_QUEUED)
            ->update(['status' => Deployment::STATUS_PENDING]);

        if ($claimed === 0) {
            return null;
        }

        // Older queued rows are covered by the newer action we just claimed.
        Deployment::query()
            ->where('server_name', $self)
            ->where('status', Deployment::STATUS_QUEUED)
            ->where('id', '<', $next->id)
            ->update([
                'status' => Deployment::STATUS_SUPERSEDED,
                'failure_reason' => 'Superseded by a newer queued fleet action.',
                'finished_at' => now(),
            ]);

        $next->refresh();

        try {
            $this->spawn($next);
        } catch (\Throwable $e) {
            // Put the row back so the next heartbeat retries a transient spawn
            // failure; prune expires it if the failure is permanent.
            Deployment::query()
                ->whereKey($next->getKey())
                ->where('status', Deployment::STATUS_PENDING)
                ->update(['status' => Deployment::STATUS_QUEUED]);
            Log::warning('Slipstream queued fleet action spawn failed: '.$e->getMessage());

            return null;
        }

        Activity::log('deploy.queue_claimed', [
            'deployment' => $next->id,
            'trigger' => $next->trigger,
            'batch' => $next->fleetBatch(),
        ]);

        return $next;
    }

    /**
     * Spawn the detached process that runs one deployment row on this node.
     * Throws on failure — the caller decides between fail-loudly (local row)
     * and revert-for-retry (claimed queued row).
     */
    protected function spawn(Deployment $deployment): void
    {
        $command = $deployment->trigger === Deployment::TRIGGER_ROLLBACK
            ? 'slipstream:rollback'
            : 'slipstream:deploy';

        $this->detacher->spawnArtisan([$command, '--deployment='.$deployment->id]);
    }
}
