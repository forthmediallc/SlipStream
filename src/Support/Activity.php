<?php

namespace Slipstream\Support;

use Slipstream\Models\ActivityLog;
use Slipstream\Slipstream;

/**
 * Records who did what in the dashboard. Every state-changing dashboard action
 * writes one row so deploy history + settings/secret/pipeline/server changes
 * form a single audit trail (Envoyer "activity" parity).
 */
class Activity
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function log(string $action, array $meta = [], ?string $subject = null): void
    {
        try {
            ActivityLog::query()->create([
                'user_id' => auth()->id(),
                'action' => $action,
                'subject' => $subject,
                'meta' => $meta !== [] ? $meta : null,
                'server_name' => Slipstream::serverName(),
            ]);
        } catch (\Throwable) {
            // Auditing must never break the action it records.
        }
    }
}
