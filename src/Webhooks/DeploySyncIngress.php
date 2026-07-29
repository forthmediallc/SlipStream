<?php

namespace Slipstream\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Slipstream\Fleet\DeployQueue;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\PeerSignature;

/**
 * Ingress for deploy-queue nudges: a signed, content-free "check your deploy
 * queue now" from the peer that queued a fleet action. Verifies the
 * domain-separated HMAC, then claims + spawns this node's newest queued row.
 * The claim is atomic and the deploy process detaches immediately, so the
 * request stays fast; each node's heartbeat claims the same row within a
 * minute regardless, so a lost nudge only costs latency.
 */
class DeploySyncIngress
{
    public function __construct(
        protected SettingsRepository $settings,
        protected DeployQueue $queue,
    ) {}

    public function handle(Request $request): Response
    {
        $raw = $request->getContent();

        // Reject oversized bodies before touching them — endpoint is unauthenticated.
        $maxBytes = (int) config('slipstream.webhook.max_body_bytes', 0);
        if ($maxBytes > 0 && strlen($raw) > $maxBytes) {
            return response('payload too large', 413);
        }

        // Fleet key, not the provider-shared webhook secret — see
        // SettingsRepository::peerSecret().
        $secret = (string) ($this->settings->peerSecret() ?? '');
        $verified = PeerSignature::verify(
            DeployQueue::SIGNING_VERSION,
            $raw,
            $secret,
            (string) $request->header('X-Slipstream-Signature', ''),
            (string) $request->header('X-Slipstream-Timestamp', ''),
            (int) config('slipstream.webhook.relay.max_skew_seconds', 300),
        );

        if (! $verified) {
            Log::warning('Slipstream deploy-sync nudge rejected (bad signature) from '.$request->ip());

            return response('invalid signature', 401);
        }

        try {
            $claimed = $this->queue->processQueued();
        } catch (\Throwable $e) {
            Log::warning('Slipstream deploy-sync pickup failed: '.$e->getMessage());

            return response('pickup failed; heartbeat will retry', 202);
        }

        return $claimed
            ? response('queued action dispatched', 200)
            : response('nothing claimable', 200);
    }
}
