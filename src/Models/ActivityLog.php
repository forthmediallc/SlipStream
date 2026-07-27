<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'slipstream_activity_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'subject',
        'meta',
        'server_name',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}
