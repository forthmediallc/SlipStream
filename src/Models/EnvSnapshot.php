<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Encrypted snapshot of one node's shared .env, published by its heartbeat so
 * the dashboard can view/edit that server's file and compute env drift.
 */
class EnvSnapshot extends Model
{
    protected $table = 'slipstream_env_snapshots';

    protected $fillable = [
        'server_name',
        'contents',
        'hash',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'contents' => 'encrypted',
            'captured_at' => 'datetime',
        ];
    }
}
