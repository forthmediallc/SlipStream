<?php

namespace Slipstream\Models;

class Setting extends SlipstreamModel
{
    protected $table = 'slipstream_settings';

    protected $fillable = [
        'key',
        'value',
        'encrypted',
    ];

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
        ];
    }
}
