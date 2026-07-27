<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;
use Slipstream\Webhooks\RelayFanout;

class RelaySecurityTest extends TestCase
{
    protected string $secret = 'relay-secret-value';

    protected int $pipelineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProcessDetacher::class, new FakeProcessDetacher);
        app(SettingsRepository::class)->set('webhook_secret', $this->secret, encrypted: true);

        $this->pipelineId = Pipeline::query()->create([
            'name' => 'main',
            'branch_pattern' => 'main',
            'auto_deploy' => true,
            'enabled' => true,
            'server_names' => null,
        ])->id;
    }

    public function test_valid_relay_ping_is_accepted(): void
    {
        $this->postRelay($this->relayBody('r-1'), time())->assertOk();
        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_github_style_signature_is_not_valid_as_a_relay_signature(): void
    {
        $body = $this->relayBody('r-forge');
        $timestamp = time();

        // A plain HMAC of the body (what a captured GitHub delivery carries) must
        // NOT verify on the relay endpoint thanks to domain separation.
        $forged = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        $this->postRelayRaw($body, $forged, (string) $timestamp)->assertStatus(401);

        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        // Correct signature but the timestamp is well outside the skew window.
        $stale = time() - 4000;
        $this->postRelay($this->relayBody('r-stale'), $stale)->assertStatus(401);
        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_relay_replay_within_window_is_deduped(): void
    {
        $body = $this->relayBody('r-replay');
        $timestamp = time();

        $this->postRelay($body, $timestamp)->assertOk();
        $this->assertSame(1, Deployment::query()->count());

        // Exact replay (same signed bytes, same nonce) inside the window.
        $this->postRelay($body, $timestamp)->assertOk();
        $this->assertSame(1, Deployment::query()->count(), 'relay replay must not deploy again');
    }

    protected function postRelay(string $body, int $timestamp)
    {
        $signature = 'sha256='.hash_hmac('sha256', RelayFanout::signingPayload((string) $timestamp, $body), $this->secret);

        return $this->postRelayRaw($body, $signature, (string) $timestamp);
    }

    protected function postRelayRaw(string $body, string $signature, string $timestamp)
    {
        $server = $this->transformHeadersToServerVars([
            'X-Slipstream-Relay' => '1',
            'X-Slipstream-Signature' => $signature,
            'X-Slipstream-Timestamp' => $timestamp,
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', '/slipstream/hooks/relay', [], [], [], $server, $body);
    }

    protected function relayBody(string $deliveryId): string
    {
        return json_encode([
            'sha' => str_repeat('b', 40),
            'branch' => 'main',
            'ref' => 'refs/heads/main',
            'commit_message' => 'from parent',
            'commit_author' => 'Dev',
            'pipeline_id' => $this->pipelineId,
            'from_server' => 'relay-node',
            'delivery_id' => $deliveryId,
            'repository' => 'org/app',
            'ping' => false,
        ], JSON_THROW_ON_ERROR);
    }
}
