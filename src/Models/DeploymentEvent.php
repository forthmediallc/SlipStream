<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentEvent extends SlipstreamModel
{
    public $timestamps = false;

    protected $table = 'slipstream_deployment_events';

    protected $fillable = [
        'deployment_id',
        'sequence',
        'type',
        'step',
        'level',
        'message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'sequence' => 'integer',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'deployment_id');
    }
}
