<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Slipstream\Support\AuditLog;

class Deployment extends SlipstreamModel
{
    // Created for a REMOTE server by a fleet-wide dashboard action; that
    // server claims it (queued → pending) via its heartbeat or a signed nudge
    // and runs it locally. Only ever seen on rows for other nodes.
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PENDING = 'pending';

    public const STATUS_BUILDING = 'building';

    public const STATUS_DEPLOYED = 'deployed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled-back';

    // A trigger that arrived while a deploy held the lock; its work is covered
    // by the in-progress deploy's catch-up, so it is not a real failure.
    public const STATUS_SUPERSEDED = 'superseded';

    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_ROLLBACK = 'rollback';

    public const TRIGGER_CATCH_UP = 'catch-up';

    protected $table = 'slipstream_deployments';

    protected $fillable = [
        'server_name',
        'sha',
        'branch',
        'commit_message',
        'commit_author',
        'trigger',
        'status',
        'duration_ms',
        'initiator_user_id',
        'release_path',
        'failure_reason',
        'warnings',
        'meta',
        'pipeline_id',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * Every row and every status change is an audit event: pending/queued at
     * creation, then building, deployed, failed, rolled-back, superseded.
     * Query-builder updates (claims, watchdog sweeps) bypass model events and
     * record themselves.
     */
    protected static function booted(): void
    {
        static::created(fn (Deployment $deployment) => $deployment->audit());
        static::updated(function (Deployment $deployment) {
            if ($deployment->wasChanged('status')) {
                $deployment->audit();
            }
        });
    }

    public function audit(): void
    {
        AuditLog::record('deployment.'.$this->status, [
            'deployment' => $this->id,
            'server' => $this->server_name,
            'trigger' => $this->trigger,
            'sha' => $this->sha,
            'branch' => $this->branch,
            'pipeline' => $this->pipeline_id,
            'user' => $this->initiator_user_id,
            'reason' => $this->failure_reason,
        ]);
    }

    /**
     * Hook IDs to skip for this deploy only (Envoyer-style deploy modal).
     *
     * @return list<int>
     */
    public function skippedHookIds(): array
    {
        $ids = $this->meta['skip_hook_ids'] ?? [];
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }

    public function deployFrom(): string
    {
        $from = $this->meta['deploy_from'] ?? 'default';

        return in_array($from, ['default', 'branch', 'tag'], true) ? $from : 'default';
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeploymentEvent::class, 'deployment_id')->orderBy('sequence');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_DEPLOYED,
            self::STATUS_FAILED,
            self::STATUS_ROLLED_BACK,
        ], true);
    }

    public function shortSha(): ?string
    {
        return $this->sha ? substr($this->sha, 0, 7) : null;
    }

    /**
     * Batch id shared by every row a single fleet-wide action created.
     */
    public function fleetBatch(): ?string
    {
        $batch = $this->meta['fleet_batch'] ?? null;

        return is_string($batch) && $batch !== '' ? $batch : null;
    }
}
