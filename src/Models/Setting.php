<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
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
