<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Environment\EnvSync;
use Slipstream\Fleet\DeployQueue;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Livewire\SettingsPage;
use Slipstream\Models\Deployment;
use Slipstream\Models\EnvSnapshot;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\PeerSignature;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;
use Slipstream\Webhooks\RelayFanout;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PCI mode: the provider hook is the only thing this node does. Everything
 * that could widen scope — dashboard, .env editor and sync, relay, the fleet
 * queue — is absent, not merely switched off behind a gate.
 */
class PciModeTest extends TestCase
{
    protected string $secret = 'pci-webhook-secret';

    protected FakeProcessDetacher $detacher;

    protected string $root;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Routes and Livewire components are registered while the provider
        // boots, so the flag has to be in place before then — not in setUp().
        $app['config']->set('slipstream.pci.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Outside PCI mode this opens the dashboard to everyone.
        Slipstream::auth(fn () => true);

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);
        app(SettingsRepository::class)->set('webhook_secret', $this->secret, encrypted: true);

        Pipeline::query()->create([
            'name' => 'main',
            'branch_pattern' => 'main',
            'auto_deploy' => true,
            'enabled' => true,
            'server_names' => null,
        ]);

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-pci-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'.env', "APP_ENV=production\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_the_dashboard_does_not_exist(): void
    {
        foreach (['/slipstream', '/slipstream/deployments', '/slipstream/webhook', '/slipstream/environment', '/slipstream/settings', '/slipstream/deploy-hooks'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_the_gate_denies_everyone_whatever_the_host_configured(): void
    {
        $this->assertFalse(Slipstream::check(request()));

        $this->expectException(HttpException::class);

        (new SettingsPage)->boot();
    }

    public function test_peer_endpoints_do_not_exist_even_for_correctly_signed_requests(): void
    {
        $timestamp = (string) time();
        $nudge = json_encode(['batch' => 'b-1', 'from_server' => 'peer-1'], JSON_THROW_ON_ERROR);

        $this->postSigned(
            '/slipstream/hooks/env-sync',
            $nudge,
            PeerSignature::sign(EnvSync::SIGNING_VERSION, $timestamp, $nudge, $this->secret),
            $timestamp,
            ['X-Slipstream-Env-Sync' => '1']
        )->assertNotFound();

        $this->postSigned(
            '/slipstream/hooks/deploy-sync',
            $nudge,
            PeerSignature::sign(DeployQueue::SIGNING_VERSION, $timestamp, $nudge, $this->secret),
            $timestamp,
            ['X-Slipstream-Deploy-Sync' => '1']
        )->assertNotFound();

        $relay = json_encode([
            'sha' => str_repeat('b', 40), 'branch' => 'main', 'ref' => 'refs/heads/main',
            'pipeline_id' => Pipeline::query()->value('id'), 'from_server' => 'relay-node',
            'delivery_id' => 'r-1', 'ping' => false,
        ], JSON_THROW_ON_ERROR);

        $this->postSigned(
            '/slipstream/hooks/relay',
            $relay,
            'sha256='.hash_hmac('sha256', RelayFanout::signingPayload($timestamp, $relay), $this->secret),
            $timestamp,
            ['X-Slipstream-Relay' => '1']
        )->assertNotFound();

        $this->assertSame(0, Deployment::query()->count());
        $this->assertCount(0, $this->detacher->calls);
    }

    /**
     * The relay provider is chosen by its headers, not by the route, so a
     * fleet-key-signed push aimed at the deploy hook would otherwise be
     * accepted as a relay. In PCI mode it is refused and recorded.
     */
    public function test_a_relay_signed_delivery_to_the_deploy_hook_is_refused(): void
    {
        app(SettingsRepository::class)->set('peer_secret', 'fleet-key', encrypted: true);
        $timestamp = (string) time();
        $relay = json_encode([
            'sha' => str_repeat('b', 40), 'branch' => 'main', 'ref' => 'refs/heads/main',
            'pipeline_id' => Pipeline::query()->value('id'), 'from_server' => 'relay-node',
            'delivery_id' => 'r-2', 'ping' => false,
        ], JSON_THROW_ON_ERROR);

        $this->postSigned(
            '/slipstream/hooks/deploy',
            $relay,
            'sha256='.hash_hmac('sha256', RelayFanout::signingPayload($timestamp, $relay), 'fleet-key'),
            $timestamp,
            ['X-Slipstream-Relay' => '1']
        )->assertStatus(403);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame('rejected', $delivery->outcome);
        $this->assertSame('relay', $delivery->provider);
        $this->assertSame(0, Deployment::query()->count());
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_a_signed_provider_push_still_deploys(): void
    {
        $this->postGithubPush('d-1')->assertOk();

        $this->assertSame(1, Deployment::query()->count());
        $this->assertSame('accepted', WebhookDelivery::query()->sole()->outcome);
        $this->assertContains('slipstream:deploy', $this->spawned());
    }

    public function test_relay_mode_in_the_settings_table_is_ignored(): void
    {
        app(SettingsRepository::class)->set('relay_enabled', '1');
        Server::query()->create([
            'server_name' => 'peer-1', 'base_url' => 'https://peer1.example',
            'enabled' => true, 'paused' => false,
        ]);
        Http::fake();

        $this->assertFalse(app(RelayFanout::class)->enabled());

        $this->postGithubPush('d-relay')->assertOk();

        $this->assertNotContains('slipstream:relay', $this->spawned());
        Http::assertNothingSent();
    }

    public function test_env_sync_is_off_and_the_heartbeat_publishes_no_snapshot(): void
    {
        config()->set('slipstream.environment.sync_enabled', true);

        $this->assertFalse(EnvSync::enabled());

        app(Heartbeat::class)->pulse();

        $this->assertSame(0, EnvSnapshot::query()->count());
        $this->assertTrue(
            Server::query()->where('server_name', 'test-node')->exists(),
            'the registry heartbeat itself still runs'
        );
    }

    public function test_a_queued_fleet_action_is_never_claimed(): void
    {
        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false]);
        $queued = Deployment::query()->create([
            'server_name' => 'test-node', 'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL, 'status' => Deployment::STATUS_QUEUED,
        ]);

        $this->assertNull(app(DeployQueue::class)->processQueued());
        $this->artisan('slipstream:heartbeat')->assertExitCode(0);

        $this->assertSame(Deployment::STATUS_QUEUED, $queued->fresh()->status);
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_peer_commands_do_nothing(): void
    {
        $this->artisan('slipstream:env-nudge')->expectsOutputToContain('PCI mode')->assertExitCode(0);
        $this->artisan('slipstream:deploy-nudge')->expectsOutputToContain('PCI mode')->assertExitCode(0);
        $this->artisan('slipstream:relay', ['--delivery' => 1])->expectsOutputToContain('PCI mode')->assertExitCode(0);
    }

    public function test_doctor_reports_the_mode_and_an_unrestricted_hook_source(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('provider hook only')
            ->expectsOutputToContain('any address can reach the hook')
            ->assertExitCode(1); // the bare layout fails elsewhere
    }

    public function test_doctor_points_a_pci_node_on_the_webhook_at_poll_mode(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('webhook, in PCI mode')
            ->expectsOutputToContain('SLIPSTREAM_TRIGGER=poll')
            ->assertExitCode(1);
    }

    public function test_doctor_warns_when_relay_is_configured_but_ignored(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);
        app(SettingsRepository::class)->set('relay_enabled', '1');

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('relay_enabled is set')
            ->assertExitCode(1);
    }

    public function test_doctor_accepts_a_restricted_hook_source(): void
    {
        Http::fake(['*' => Http::response('source not allowed', 403)]);
        config()->set('slipstream.webhook.allowed_ips', ['140.82.112.0/20']);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('reachable, source restricted')
            ->expectsOutputToContain('restricted to 140.82.112.0/20')
            ->assertExitCode(1);
    }

    public function test_install_without_the_flag_keeps_behaving_as_pci_on_a_pci_node(): void
    {
        $this->artisan('slipstream:install')
            ->expectsOutputToContain('the only route registered in PCI mode')
            ->assertExitCode(0);
    }

    /** @return list<string> First artisan arg of every spawn call. */
    protected function spawned(): array
    {
        return array_map(fn ($c) => $c['args'][0], $this->detacher->calls);
    }

    /**
     * @param  array<string, string>  $extraHeaders
     */
    protected function postSigned(string $path, string $body, string $signature, string $timestamp, array $extraHeaders = [])
    {
        $server = $this->transformHeadersToServerVars($extraHeaders + [
            'X-Slipstream-Signature' => $signature,
            'X-Slipstream-Timestamp' => $timestamp,
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    protected function postGithubPush(string $deliveryId)
    {
        $sha = str_repeat('a', 40);
        $body = json_encode([
            'ref' => 'refs/heads/main',
            'after' => $sha,
            'head_commit' => ['id' => $sha, 'message' => 'msg', 'author' => ['name' => 'Dev']],
            'repository' => ['full_name' => 'org/app'],
            'pusher' => ['name' => 'dev'],
        ], JSON_THROW_ON_ERROR);

        $server = $this->transformHeadersToServerVars([
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => $deliveryId,
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', '/slipstream/hooks/deploy', [], [], [], $server, $body);
    }
}
