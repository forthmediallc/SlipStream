<?php

namespace Slipstream\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Slipstream;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional source allow-list for the provider hook (webhook.allowed_ips).
 *
 * The signature is the real control; this is the second one PCI reviews ask
 * for on an unauthenticated route — the endpoint simply does not talk to
 * anyone but the git host. A delivery from elsewhere is refused before its
 * body is read and recorded in the delivery log, so a misconfigured proxy (the
 * app seeing the balancer's address instead of GitHub's) shows up there as a
 * rejected row naming the address it saw, not as a silent stop.
 */
class RestrictWebhookSource
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = Slipstream::webhookAllowedIps();

        if ($allowed === []) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        if ($ip !== '' && IpUtils::checkIp($ip, $allowed)) {
            return $next($request);
        }

        try {
            WebhookDelivery::query()->create([
                'provider' => 'unknown',
                'server_name' => Slipstream::serverName(),
                'signature_valid' => false,
                'outcome' => 'rejected',
                'outcome_detail' => 'Source '.($ip !== '' ? $ip : '(unknown)').' is not in webhook.allowed_ips',
            ]);
        } catch (\Throwable) {
            // The refusal stands even when it cannot be recorded.
        }

        return response('source not allowed', 403);
    }
}
