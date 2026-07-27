<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Environment\EnvSync;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\PeerSignature;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;
use Slipstream\Webhooks\RelayFanout;

/**
 * The webhook secret is shared with the git host (GitLab even sends it verbatim
 * in every X-Gitlab-Token header). Once a fleet key exists it — and only it —
 * signs peer traffic, so a webhook secret leaked at the provider can no longer
 * forge a relay push (which carries an arbitrary SHA and pipeline id) or an
 * env-sync nudge.
 */
class PeerSecretSeparationTest extends TestCase
{
    protected string $webhookSecret = 'provider-shared-secret';

    protected string $peerSecret = 'fleet-only-secret';

    protected int $pipelineId;

    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);

        app(SettingsRepository::class)->set('webhook_secret', $this->webhookSecret, encrypted: true);

        $this->pipelineId = Pipeline::query()->create([
            'name' => 'main',
            'branch_pattern' => 'main',
            'auto_deploy' => true,
            'enabled' => true,
            'server_names' => null,
        ])->id;
    }

    public function test_relay_signed_with_the_webhook_secret_is_rejected_once_a_fleet_key_exists(): void
    {
        $this->separate();

        $this->postRelay($this->relayBody('r-provider'), time(), $this->webhookSecret)
            ->assertStatus(401);

        $this->assertSame(0, Deployment::query()->count(), 'a provider secret must not drive a relay deploy');
    }

    public function test_relay_signed_with_the_fleet_key_is_accepted(): void
    {
        $this->separate();

        $this->postRelay($this->relayBody('r-fleet'), time(), $this->peerSecret)->assertOk();

        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_env_sync_signed_with_the_webhook_secret_is_rejected_once_a_fleet_key_exists(): void
    {
        $this->separate();

        $this->postEnvSync(time(), $this->webhookSecret)->assertStatus(401);

        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_env_sync_signed_with_the_fleet_key_is_accepted(): void
    {
        $this->separate();

        $this->postEnvSync(time(), $this->peerSecret)->assertOk();

        $spawned = array_map(fn ($c) => $c['args'][0], $this->detacher->calls);
        $this->assertContains('slipstream:env-apply', $spawned);
    }

    public function test_legacy_install_without_a_fleet_key_still_verifies_with_the_webhook_secret(): void
    {
        // No peer_secret set: an existing fleet mid-upgrade must keep working.
        $settings = app(SettingsRepository::class);
        $this->assertFalse($settings->hasSeparatePeerSecret());
        $this->assertSame($this->webhookSecret, $settings->peerSecret());

        $this->postRelay($this->relayBody('r-legacy'), time(), $this->webhookSecret)->assertOk();

        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_rotating_the_fleet_key_does_not_touch_the_webhook_secret(): void
    {
        $settings = app(SettingsRepository::class);

        $first = $settings->rotatePeerSecret();
        $second = $settings->rotatePeerSecret();

        $this->assertNotSame($first, $second);
        $this->assertSame($this->webhookSecret, $settings->webhookSecret());
        $this->assertSame($second, $settings->peerSecret());
    }

    protected function separate(): void
    {
        app(SettingsRepository::class)->set('peer_secret', $this->peerSecret, encrypted: true);
    }

    protected function postRelay(string $body, int $timestamp, string $secret)
    {
        $signature = 'sha256='.hash_hmac(
            'sha256',
            RelayFanout::signingPayload((string) $timestamp, $body),
            $secret
        );

        $server = $this->transformHeadersToServerVars([
            'X-Slipstream-Relay' => '1',
            'X-Slipstream-Signature' => $signature,
            'X-Slipstream-Timestamp' => (string) $timestamp,
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', '/slipstream/hooks/relay', [], [], [], $server, $body);
    }

    protected function postEnvSync(int $timestamp, string $secret)
    {
        $body = json_encode(['batch' => 'b-1', 'from_server' => 'peer-1'], JSON_THROW_ON_ERROR);
        $signature = PeerSignature::sign(EnvSync::SIGNING_VERSION, (string) $timestamp, $body, $secret);

        $server = $this->transformHeadersToServerVars([
            'X-Slipstream-Env-Sync' => '1',
            'X-Slipstream-Signature' => $signature,
            'X-Slipstream-Timestamp' => (string) $timestamp,
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', '/slipstream/hooks/env-sync', [], [], [], $server, $body);
    }

    protected function relayBody(string $deliveryId): string
    {
        return json_encode([
            'sha' => str_repeat('b', 40),
            'branch' => 'main',
            'ref' => 'refs/heads/main',
            'pipeline_id' => $this->pipelineId,
            'from_server' => 'relay-node',
            'delivery_id' => $deliveryId,
            'ping' => false,
        ], JSON_THROW_ON_ERROR);
    }
}
