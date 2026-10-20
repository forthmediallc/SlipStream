<?php

namespace Slipstream\Webhooks\Providers;

use Illuminate\Http\Request;
use Slipstream\Slipstream;
use Slipstream\Support\PeerSignature;
use Slipstream\Webhooks\PushEvent;
use Slipstream\Webhooks\RelayFanout;

/**
 * Internal Slipstream fan-out from a relay node. Verifies a domain-separated,
 * timestamped HMAC so a captured provider signature can't be replayed here and
 * an old relay capture can't be replayed outside the skew window.
 */
class RelayProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'relay';
    }

    public function matches(Request $request): bool
    {
        return $request->hasHeader('X-Slipstream-Signature')
            || $request->header('X-Slipstream-Relay') === '1'
            || $request->is(Slipstream::hookPath('relay'));
    }

    public function parse(Request $request, string $rawBody, ?string $secret): PushEvent
    {
        $header = (string) $request->header('X-Slipstream-Signature', '');
        $timestamp = (string) $request->header('X-Slipstream-Timestamp', '');
        $valid = $this->verify($rawBody, (string) $secret, $header, $timestamp);

        $payload = json_decode($rawBody, true) ?: [];
        $ref = data_get($payload, 'ref');
        $branch = data_get($payload, 'branch');
        if (! $ref && is_string($branch)) {
            $ref = 'refs/heads/'.$branch;
        }

        return new PushEvent(
            provider: $this->name(),
            event: 'relay',
            deliveryId: data_get($payload, 'delivery_id') ?: $request->header('X-Slipstream-Delivery'),
            ref: is_string($ref) ? $ref : null,
            sha: is_string($s = data_get($payload, 'sha')) ? $s : null,
            commitMessage: is_string($m = data_get($payload, 'commit_message')) ? $m : null,
            commitAuthor: is_string($a = data_get($payload, 'commit_author')) ? $a : null,
            signatureValid: $valid,
            isPing: (bool) data_get($payload, 'ping', false),
            meta: [
                'from_server' => data_get($payload, 'from_server'),
                'pipeline_id' => data_get($payload, 'pipeline_id'),
                'repository' => data_get($payload, 'repository'),
            ],
        );
    }

    protected function verify(string $payload, string $secret, string $header, string $timestamp): bool
    {
        return PeerSignature::verify(
            RelayFanout::SIGNING_VERSION,
            $payload,
            $secret,
            $header,
            $timestamp,
            (int) config('slipstream.webhook.relay.max_skew_seconds', 300),
        );
    }
}
