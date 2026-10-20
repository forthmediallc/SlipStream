<?php

namespace Slipstream\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Slipstream;
use Slipstream\Webhooks\WebhookSourceRanges;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional source allow-list for the provider hook (webhook.allowed_ips):
 * addresses, CIDRs, and the keywords github / bitbucket, which stand for the
 * provider's published hook ranges (WebhookSourceRanges).
 *
 * The signature is the real control; this is the second one PCI reviews ask
 * for on an unauthenticated route — the endpoint simply does not talk to
 * anyone but the git host. A delivery from elsewhere is refused before its
 * body is read and recorded in the delivery log, so a misconfigured proxy (the
 * app seeing the balancer's address instead of GitHub's) shows up there as a
 * rejected row naming the address it saw, not as a silent stop. A keyword
 * whose ranges were never downloaded matches nothing, and the row says so.
 */
class RestrictWebhookSource
{
    public function __construct(protected WebhookSourceRanges $ranges) {}

    public function handle(Request $request, Closure $next): Response
    {
        $configured = Slipstream::webhookAllowedIps();

        if ($configured === []) {
            return $next($request);
        }

        $resolved = $this->ranges->resolve($configured);
        $ip = (string) $request->ip();

        if ($ip !== '' && $resolved['cidrs'] !== [] && IpUtils::checkIp($ip, $resolved['cidrs'])) {
            return $next($request);
        }

        $detail = 'Source '.($ip !== '' ? $ip : '(unknown)').' is not in webhook.allowed_ips';
        if ($resolved['missing'] !== []) {
            $detail .= '; the '.implode(', ', $resolved['missing']).' ranges have never been downloaded (php artisan slipstream:webhook-ranges refresh)';
        }

        try {
            WebhookDelivery::query()->create([
                'provider' => 'unknown',
                'server_name' => Slipstream::serverName(),
                'signature_valid' => false,
                'outcome' => 'rejected',
                'outcome_detail' => $detail,
            ]);
        } catch (\Throwable) {
            // The refusal stands even when it cannot be recorded.
        }

        return response('source not allowed', 403);
    }
}
