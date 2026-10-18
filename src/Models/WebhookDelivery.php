<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Slipstream\Support\AuditLog;

class WebhookDelivery extends SlipstreamModel
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

    /**
     * A delivery is an audit event the moment it has an outcome — accepted,
     * rejected, ignored, spawn_failed — whether that is set at creation (a
     * refusal before parsing) or by the update that concludes the ingress.
     */
    protected static function booted(): void
    {
        static::created(function (WebhookDelivery $delivery) {
            if ($delivery->outcome !== null) {
                $delivery->audit();
            }
        });
        static::updated(function (WebhookDelivery $delivery) {
            if ($delivery->wasChanged('outcome')) {
                $delivery->audit();
            }
        });
    }

    public function audit(): void
    {
        AuditLog::record('webhook.'.$this->outcome, [
            'delivery' => $this->id,
            'provider' => $this->provider,
            'delivery_id' => $this->delivery_id,
            'event' => $this->event,
            'ref' => $this->ref,
            'sha' => $this->sha,
            'signature_valid' => $this->signature_valid,
            'deployment' => $this->deployment_id,
            'detail' => $this->outcome_detail,
        ]);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'deployment_id');
    }
}
