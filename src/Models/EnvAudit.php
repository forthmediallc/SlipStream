<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Model;

class EnvAudit extends Model
{
    protected $table = 'slipstream_env_audits';

    protected $fillable = [
        'user_id',
        'action',
        'backup_path',
        'bytes',
        'server_name',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }
}
