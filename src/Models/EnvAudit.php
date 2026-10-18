<?php

namespace Slipstream\Models;

class EnvAudit extends SlipstreamModel
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
