<?php

namespace Slipstream\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Environment\EnvSync;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\PeerSignature;

/**
 * Ingress for env-sync nudges: a signed, content-free "check your queue now"
 * from a peer. Verifies the domain-separated HMAC, then spawns a detached
 * slipstream:env-apply so the file write never rides this web request. Each
 * node's heartbeat applies the same pending row within a minute regardless, so
 * a spawn failure simply falls back to that.
 */
class EnvSyncIngress
{
    public function __construct(
        protected SettingsRepository $settings,
        protected ProcessDetacher $detacher,
    ) {}

    public function handle(Request $request): Response
    {
        if (! EnvSync::enabled()) {
            return response('env sync disabled', 404);
        }

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
            EnvSync::SIGNING_VERSION,
            $raw,
            $secret,
            (string) $request->header('X-Slipstream-Signature', ''),
            (string) $request->header('X-Slipstream-Timestamp', ''),
            (int) config('slipstream.webhook.relay.max_skew_seconds', 300),
        );

        if (! $verified) {
            Log::warning('Slipstream env-sync nudge rejected (bad signature) from '.$request->ip());

            return response('invalid signature', 401);
        }

        try {
            $this->detacher->spawnArtisan(['slipstream:env-apply']);
        } catch (\Throwable $e) {
            Log::warning('Slipstream env-apply spawn failed: '.$e->getMessage());

            return response('spawn failed; heartbeat will apply', 202);
        }

        return response('apply dispatched', 200);
    }
}
