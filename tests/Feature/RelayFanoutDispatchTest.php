<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Server;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Models\Pipeline;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

class RelayFanoutDispatchTest extends TestCase
{
    protected string $secret = 'relay-dispatch-secret';

    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);

        $s = app(SettingsRepository::class);
        $s->set('webhook_secret', $this->secret, encrypted: true);
        $s->set('relay_enabled', '1');

        Pipeline::query()->create([
            'name' => 'main', 'branch_pattern' => 'main',
            'auto_deploy' => true, 'enabled' => true, 'server_names' => null,
        ]);

        Server::query()->create([
            'server_name' => 'peer-1', 'base_url' => 'https://peer1.example',
            'enabled' => true, 'paused' => false,
        ]);
    }

    public function test_deploy_success_path_dispatches_relay_and_preserves_pending_payload(): void
    {
        $this->postGithubPush()->assertOk();

        $delivery = WebhookDelivery::query()->latest('id')->first();

        // The bug this guards: the success path must NOT drop relay_pending.
        $this->assertIsArray(data_get($delivery->payload_meta, 'relay_pending'), 'relay_pending must survive the success-path update');
        $this->assertSame('main', data_get($delivery->payload_meta, 'relay_pending.branch'));

        $spawned = array_map(fn ($c) => $c['args'][0], $this->detacher->calls);
        $this->assertContains('slipstream:relay', $spawned, 'a detached relay fan-out must be dispatched');
        $this->assertContains('slipstream:deploy', $spawned);
    }

    public function test_relay_command_fans_out_stored_payload_to_peers(): void
    {
        Http::fake();

        $this->postGithubPush()->assertOk();
        $delivery = WebhookDelivery::query()->latest('id')->first();

        $this->artisan('slipstream:relay', ['--delivery' => $delivery->id])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://peer1.example')
                && str_contains($request->url(), '/hooks/relay')
                && $request->hasHeader('X-Slipstream-Signature')
                && $request->hasHeader('X-Slipstream-Timestamp');
        });
    }

    protected function postGithubPush()
    {
        $sha = str_repeat('e', 40);
        $body = json_encode([
            'ref' => 'refs/heads/main', 'after' => $sha,
            'head_commit' => ['id' => $sha, 'message' => 'msg', 'author' => ['name' => 'Dev']],
            'repository' => ['full_name' => 'org/app'], 'pusher' => ['name' => 'dev'],
        ], JSON_THROW_ON_ERROR);

        $server = $this->transformHeadersToServerVars([
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'fan-'.bin2hex(random_bytes(4)),
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', '/slipstream/hooks/deploy', [], [], [], $server, $body);
    }
}
