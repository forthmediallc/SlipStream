<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Environment\EnvSync;
use Slipstream\Models\EnvPush;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

class EnvSyncQueueTest extends TestCase
{
    protected string $root;

    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/ss-envsync-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);
        file_put_contents($this->root.'/.env', "APP_ENV=production\nSLIPSTREAM_SERVER=test-node\n");

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);

        app(SettingsRepository::class)->set('webhook_secret', 'env-secret', encrypted: true);

        Server::query()->create([
            'server_name' => 'peer-1', 'base_url' => 'https://peer1.example',
            'enabled' => true, 'paused' => false,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/.env*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_queue_creates_a_row_per_target_and_applies_self_inline(): void
    {
        $baseHash = EnvSync::hashContents("APP_ENV=production\nSLIPSTREAM_SERVER=test-node\n");

        app(EnvSync::class)->queue(['test-node', 'peer-1'], "APP_ENV=production\nNEW=1\n", [
            'preserve_keys' => true,
            'base_hashes' => ['test-node' => $baseHash, 'peer-1' => null],
            'user_id' => 7,
        ]);

        $this->assertSame(2, EnvPush::query()->count());

        // Self applied inline through the same path as remote nodes.
        $self = EnvPush::query()->where('server_name', 'test-node')->first();
        $this->assertSame(EnvPush::STATUS_APPLIED, $self->status);

        $env = file_get_contents($this->root.'/.env');
        $this->assertStringContainsString('NEW=1', $env);
        $this->assertStringContainsString('SLIPSTREAM_SERVER=test-node', $env, 'per-server key preserved on merge');
        $this->assertDatabaseHas('slipstream_env_audits', ['server_name' => 'test-node', 'user_id' => 7]);

        // Remote target stays pending for its own heartbeat/nudge.
        $this->assertSame(EnvPush::STATUS_PENDING, EnvPush::query()->where('server_name', 'peer-1')->first()->status);

        $spawned = array_map(fn ($c) => $c['args'][0], $this->detacher->calls);
        $this->assertContains('slipstream:env-nudge', $spawned);
    }

    public function test_queue_supersedes_older_pending_for_same_target(): void
    {
        $sync = app(EnvSync::class);
        $sync->queue(['peer-1'], "A=1\n");
        $sync->queue(['peer-1'], "A=2\n");

        $this->assertSame(1, EnvPush::query()->where('server_name', 'peer-1')->where('status', EnvPush::STATUS_PENDING)->count());
        $this->assertSame(1, EnvPush::query()->where('server_name', 'peer-1')->where('status', EnvPush::STATUS_SUPERSEDED)->count());
    }

    public function test_env_nudge_posts_signed_requests_only_to_peers_with_base_url(): void
    {
        Http::fake();

        // A peer without a base_url must not be nudged (heartbeat-only).
        Server::query()->create(['server_name' => 'peer-nobase', 'enabled' => true]);

        $result = app(EnvSync::class)->queue(['peer-1', 'peer-nobase'], "A=1\n");
        $this->artisan('slipstream:env-nudge', ['--batch' => $result['batch']])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://peer1.example')
                && str_contains($request->url(), '/hooks/env-sync')
                && $request->hasHeader('X-Slipstream-Signature')
                && $request->hasHeader('X-Slipstream-Timestamp');
        });
        Http::assertSentCount(1);
    }

    public function test_env_nudge_without_a_secret_sends_nothing(): void
    {
        Http::fake();
        app(SettingsRepository::class)->forget('webhook_secret');

        $result = app(EnvSync::class)->queue(['peer-1'], "A=1\n");
        $this->artisan('slipstream:env-nudge', ['--batch' => $result['batch']])->assertExitCode(0);

        Http::assertNothingSent();
    }
}
