<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $table = 'slipstream_webhook_deliveries';

    protected $fillable = [
        'provider',
        'server_name',
        'delivery_id',
        'event',
        'ref',
        'sha',
        'signature_valid',
        'matched_pipeline',
        'outcome',
        'outcome_detail',
        'deployment_id',
        'payload_meta',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'matched_pipeline' => 'boolean',
            'payload_meta' => 'array',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'deployment_id');
    }
}
