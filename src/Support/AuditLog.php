<?php

namespace Slipstream\Support;

use Illuminate\Support\Facades\Log;
use Slipstream\Slipstream;

/**
 * Structured audit lines for the host's log pipeline.
 *
 * The slipstream_* tables already hold every delivery, deployment and action,
 * but a table is only as tamper-proof as its least-privileged writer — the
 * deploy user can UPDATE it. PCI DSS req. 10.3.2/10.3.3 want audit records
 * protected from modification and promptly copied to a central log server,
 * and the way a Laravel app does that is its log channel: whatever ships
 * laravel.log off the box (syslog, CloudWatch, a SIEM) ships these lines too.
 *
 * One line per event, `slipstream.<event>` with a flat context. Values are
 * never secrets: hook output, .env contents and settings values stay out.
 */
final class AuditLog
{
    /**
     * On in PCI mode unless configured otherwise; configurable on elsewhere.
     */
    public static function enabled(): bool
    {
        $configured = config('slipstream.audit.enabled');

        if ($configured === null || $configured === '') {
            return Slipstream::pciMode();
        }

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $event, array $context = []): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            // `node` is the server writing the line; an event's own `server`
            // (a queued row's target, a push's destination) stays in context.
            $context = ['node' => Slipstream::serverName()] + $context;
            $channel = config('slipstream.audit.channel');

            if (is_string($channel) && $channel !== '') {
                Log::channel($channel)->info('slipstream.'.$event, $context);
            } else {
                Log::info('slipstream.'.$event, $context);
            }
        } catch (\Throwable) {
            // Auditing must never break the action it records.
        }
    }
}
