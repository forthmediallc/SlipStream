<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Environment\EnvSync;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\PeerSignature;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;
use Slipstream\Webhooks\RelayFanout;

class EnvSyncSecurityTest extends TestCase
{
    protected string $secret = 'env-sync-secret-value';

    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);
        app(SettingsRepository::class)->set('webhook_secret', $this->secret, encrypted: true);
    }

    public function test_valid_nudge_dispatches_a_detached_apply(): void
    {
        $this->postEnvSync($this->body(), time())->assertOk();

        $spawned = array_map(fn ($c) => $c['args'][0], $this->detacher->calls);
        $this->assertContains('slipstream:env-apply', $spawned);
    }

    public function test_relay_signature_is_not_valid_on_the_env_sync_endpoint(): void
    {
        $body = $this->body();
        $ts = (string) time();

        // A valid RELAY-domain signature must not verify here (domain separation).
        $relaySig = 'sha256='.hash_hmac('sha256', RelayFanout::signingPayload($ts, $body), $this->secret);

        $this->postEnvSyncRaw($body, $relaySig, $ts)->assertStatus(401);
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_env_sync_signature_is_not_valid_on_the_relay_endpoint(): void
    {
        $body = json_encode([
            'sha' => str_repeat('a', 40), 'ref' => 'refs/heads/main', 'delivery_id' => 'x',
        ], JSON_THROW_ON_ERROR);
        $ts = (string) time();

        $envSig = PeerSignature::sign(EnvSync::SIGNING_VERSION, $ts, $body, $this->secret);

        $server = $this->transformHeadersToServerVars([
            'X-Slipstream-Relay' => '1',
            'X-Slipstream-Signature' => $envSig,
            'X-Slipstream-Timestamp' => $ts,
            'Content-Type' => 'application/json',
        ]);

        $this->call('POST', '/slipstream/hooks/relay', [], [], [], $server, $body)->assertStatus(401);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $this->postEnvSync($this->body(), time() - 4000)->assertStatus(401);
    }

    public function test_plain_body_hmac_is_rejected(): void
    {
        $body = $this->body();
        $forged = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        $this->postEnvSyncRaw($body, $forged, (string) time())->assertStatus(401);
    }

    public function test_disabled_sync_returns_404(): void
    {
        config()->set('slipstream.environment.sync_enabled', false);

        $this->postEnvSync($this->body(), time())->assertStatus(404);
    }

    public function test_oversized_body_is_rejected(): void
    {
        config()->set('slipstream.webhook.max_body_bytes', 10);

        $body = json_encode(['batch' => str_repeat('x', 100)], JSON_THROW_ON_ERROR);
        $this->postEnvSync($body, time())->assertStatus(413);
    }

    protected function body(): string
    {
        return json_encode(['batch' => 'b-1', 'from_server' => 'peer-1'], JSON_THROW_ON_ERROR);
    }

    protected function postEnvSync(string $body, int $timestamp)
    {
        $sig = PeerSignature::sign(EnvSync::SIGNING_VERSION, (string) $timestamp, $body, $this->secret);

        return $this->postEnvSyncRaw($body, $sig, (string) $timestamp);
    }

    protected function postEnvSyncRaw(string $body, string $signature, string $timestamp)
    {
        $server = $this->transformHeadersToServerVars([
            'X-Slipstream-Env-Sync' => '1',
            'X-Slipstream-Signature' => $signature,
            'X-Slipstream-Timestamp' => $timestamp,
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', '/slipstream/hooks/env-sync', [], [], [], $server, $body);
    }
}
