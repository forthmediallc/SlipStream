<?php

namespace Slipstream\Models;

/**
 * A queued .env update for one target server. Applied by that node's heartbeat
 * (or an instant signed nudge). The shared-DB row is the source of truth.
 */
class EnvPush extends SlipstreamModel
{
    public const STATUS_PENDING = 'pending';

    // Claimed by an applier; a transient lock so a nudge-spawned apply and the
    // minutely heartbeat can never both write the file.
    public const STATUS_APPLYING = 'applying';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    // A newer push for the same server replaced this one before it applied.
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const TERMINAL = [
        self::STATUS_APPLIED,
        self::STATUS_FAILED,
        self::STATUS_SUPERSEDED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'slipstream_env_pushes';

    protected $fillable = [
        'batch',
        'server_name',
        'contents',
        'base_hash',
        'preserve_keys',
        'status',
        'error',
        'user_id',
        'note',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'contents' => 'encrypted',
            'preserve_keys' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }
}
