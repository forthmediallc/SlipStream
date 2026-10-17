<?php

namespace Slipstream\Webhooks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\PeerSignature;

/**
 * One GitHub/GitLab/Bitbucket webhook hits the relay node; it fans out
 * signed internal POSTs to peer base_urls.
 */
class RelayFanout
{
    /**
     * Domain-separation tag: relay signatures are HMAC over this prefix + a
     * timestamp + the body, so a captured provider signature (plain HMAC of the
     * body) can never be replayed as a valid relay signature, and vice versa.
     */
    public const SIGNING_VERSION = 'slipstream.relay.v1';

    public function __construct(
        protected SettingsRepository $settings
    ) {}

    /**
     * Relay mode is a dashboard setting; PCI mode overrides it, so a node in
     * that profile never fans a push out to peers whatever the table says.
     */
    public function enabled(): bool
    {
        if (Slipstream::pciMode()) {
            return false;
        }

        return filter_var($this->settings->get('relay_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The relay body fields for an event (stored for the detached fan-out or
     * signed inline).
     *
     * @return array<string, mixed>
     */
    public function payloadFrom(PushEvent $event, ?int $pipelineId = null): array
    {
        return [
            'sha' => $event->sha,
            'branch' => $event->branch(),
            'ref' => $event->ref,
            'commit_message' => $event->commitMessage,
            'commit_author' => $event->commitAuthor,
            'pipeline_id' => $pipelineId,
            'from_server' => Slipstream::serverName(),
            'delivery_id' => $event->deliveryId ?: bin2hex(random_bytes(8)),
            'repository' => $event->meta['repository'] ?? null,
            'ping' => $event->isPing,
        ];
    }

    /**
     * @deprecated Use payloadFrom() + fanOutPayload(). Kept for callers/tests.
     *
     * @return list<array{server: string, ok: bool, status: ?int, detail: string}>
     */
    public function fanOut(PushEvent $event, ?int $pipelineId = null): array
    {
        return $this->fanOutPayload($this->payloadFrom($event, $pipelineId));
    }

    /**
     * Sign and POST the relay body to every peer with a base_url.
     *
     * @param  array<string, mixed>  $fields
     * @return list<array{server: string, ok: bool, status: ?int, detail: string}>
     */
    public function fanOutPayload(array $fields): array
    {
        // Fleet key, not the provider-shared webhook secret — see
        // SettingsRepository::peerSecret().
        $secret = $this->settings->peerSecret();
        if (! $secret) {
            return [];
        }

        $path = trim(Slipstream::path(), '/');
        $body = json_encode($fields, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = (string) ($fields['delivery_id'] ?? bin2hex(random_bytes(8)));
        $signature = 'sha256='.hash_hmac('sha256', self::signingPayload($timestamp, $body), $secret);
        $timeout = (int) config('slipstream.webhook.relay.timeout', 5);
        $results = [];

        $peers = Server::query()
            ->where('enabled', true)
            ->where('server_name', '!=', Slipstream::serverName())
            ->whereNotNull('base_url')
            ->where('base_url', '!=', '')
            ->get();

        foreach ($peers as $peer) {
            $url = rtrim($peer->base_url, '/').'/'.$path.'/hooks/relay';

            try {
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-Slipstream-Relay' => '1',
                        'X-Slipstream-Signature' => $signature,
                        'X-Slipstream-Timestamp' => $timestamp,
                        'X-Slipstream-Delivery' => $nonce,
                    ])
                    ->withBody($body, 'application/json')
                    ->post($url);

                $results[] = [
                    'server' => $peer->server_name,
                    'ok' => $response->successful(),
                    'status' => $response->status(),
                    'detail' => $response->body(),
                ];
            } catch (\Throwable $e) {
                Log::warning('Slipstream relay fan-out failed for '.$peer->server_name.': '.$e->getMessage());
                $results[] = [
                    'server' => $peer->server_name,
                    'ok' => false,
                    'status' => null,
                    'detail' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * The exact bytes both sides HMAC: version tag + timestamp + body.
     */
    public static function signingPayload(string $timestamp, string $body): string
    {
        return PeerSignature::payload(self::SIGNING_VERSION, $timestamp, $body);
    }
}
