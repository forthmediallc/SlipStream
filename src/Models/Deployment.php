<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
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
}
