<?php

namespace Slipstream\Tests\Feature;

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Environment\EnvSync;
use Slipstream\Livewire\EnvironmentPage;
use Slipstream\Models\EnvPush;
use Slipstream\Models\EnvSnapshot;
use Slipstream\Models\Server;
use Slipstream\Slipstream;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

class EnvironmentPageTest extends TestCase
{
    protected string $root;

    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        Slipstream::auth(fn () => true);

        $this->root = sys_get_temp_dir().'/ss-envpage-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);
        file_put_contents($this->root.'/.env', "APP_ENV=production\nSLIPSTREAM_SERVER=test-node\n");

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);

        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/.env*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_save_requires_confirmation(): void
    {
        Livewire::test(EnvironmentPage::class)
            ->set('contents', "APP_ENV=production\nX=1\n")
            ->set('confirmDanger', false)
            ->call('save')
            ->assertHasErrors('confirmDanger');

        $this->assertSame(0, EnvPush::query()->count());
    }

    public function test_single_self_save_applies_and_writes_the_file(): void
    {
        Livewire::test(EnvironmentPage::class)
            ->set('mode', 'single')
            ->set('editServer', 'test-node')
            ->set('contents', "APP_ENV=production\nNEW=1\n")
            ->set('confirmDanger', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringContainsString('NEW=1', file_get_contents($this->root.'/.env'));

        $push = EnvPush::query()->where('server_name', 'test-node')->first();
        $this->assertSame(EnvPush::STATUS_APPLIED, $push->status);
        $this->assertFalse($push->preserve_keys, 'single-server edit writes exact content, no merge');
    }

    public function test_fleet_save_queues_enabled_peers_and_spawns_a_nudge(): void
    {
        Server::query()->create(['server_name' => 'peer-1', 'enabled' => true, 'base_url' => 'https://p1.example']);
        Server::query()->create(['server_name' => 'peer-off', 'enabled' => false]);

        Livewire::test(EnvironmentPage::class)
            ->set('mode', 'fleet')
            ->set('contents', "APP_ENV=production\nSHARED=1\n")
            ->set('confirmDanger', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(EnvPush::query()->where('server_name', 'peer-1')->exists());
        $this->assertFalse(EnvPush::query()->where('server_name', 'peer-off')->exists(), 'disabled server excluded from fleet scope');

        $peer = EnvPush::query()->where('server_name', 'peer-1')->first();
        $this->assertTrue($peer->preserve_keys);
        $this->assertSame(EnvPush::STATUS_PENDING, $peer->status);

        $spawned = array_map(fn ($c) => $c['args'][0], $this->detacher->calls);
        $this->assertContains('slipstream:env-nudge', $spawned);
    }

    public function test_custom_mode_requires_at_least_one_server(): void
    {
        Livewire::test(EnvironmentPage::class)
            ->set('mode', 'custom')
            ->set('selectedServers', [])
            ->set('contents', "A=1\n")
            ->set('confirmDanger', true)
            ->call('save')
            ->assertHasErrors('selectedServers');
    }

    public function test_single_remote_loads_snapshot_and_saves_exact_content(): void
    {
        $peerEnv = "APP_ENV=production\nSLIPSTREAM_SERVER=peer-1\n";
        Server::query()->create(['server_name' => 'peer-1', 'enabled' => true, 'base_url' => 'https://p1.example']);
        EnvSnapshot::query()->create([
            'server_name' => 'peer-1',
            'contents' => $peerEnv,
            'hash' => EnvSync::hashContents($peerEnv),
            'captured_at' => now(),
        ]);

        $component = Livewire::test(EnvironmentPage::class)
            ->set('mode', 'single')
            ->set('editServer', 'peer-1')
            ->assertSet('remoteSource', true)
            ->assertSet('contents', $peerEnv);

        $component->set('contents', "APP_ENV=production\nSLIPSTREAM_SERVER=peer-1\nEDIT=1\n")
            ->set('confirmDanger', true)
            ->call('save')
            ->assertHasNoErrors();

        $push = EnvPush::query()->where('server_name', 'peer-1')->latest('id')->first();
        $this->assertSame(EnvPush::STATUS_PENDING, $push->status);
        $this->assertFalse($push->preserve_keys, 'single-server edit must not merge');
    }

    public function test_single_remote_without_snapshot_shows_an_explainer(): void
    {
        Server::query()->create(['server_name' => 'peer-2', 'enabled' => true]);

        Livewire::test(EnvironmentPage::class)
            ->set('mode', 'single')
            ->set('editServer', 'peer-2')
            ->assertSet('loaded', false)
            ->assertSee('No snapshot yet');
    }

    public function test_cancel_pending_push(): void
    {
        $push = EnvPush::query()->create([
            'batch' => 'b1', 'server_name' => 'peer-9',
            'contents' => "A=1\n", 'preserve_keys' => true,
            'status' => EnvPush::STATUS_PENDING,
        ]);

        Livewire::test(EnvironmentPage::class)->call('cancelPush', $push->id);

        $this->assertSame(EnvPush::STATUS_CANCELLED, $push->fresh()->status);
        $this->assertDatabaseHas('slipstream_activity_log', ['action' => 'env.push_cancelled']);
    }

    public function test_disabled_sync_writes_locally_without_a_db_row(): void
    {
        config()->set('slipstream.environment.sync_enabled', false);

        Livewire::test(EnvironmentPage::class)
            ->set('contents', "APP_ENV=production\nLOCAL=1\n")
            ->set('confirmDanger', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringContainsString('LOCAL=1', file_get_contents($this->root.'/.env'));
        // The off-switch must not leave an encrypted .env blob in the shared DB.
        $this->assertSame(0, EnvPush::query()->count());
        $this->assertSame(0, EnvSnapshot::query()->count());
        $this->assertDatabaseHas('slipstream_env_audits', ['server_name' => 'test-node']);
    }

    public function test_locked_base_hash_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(EnvironmentPage::class)->set('baseHash', 'forced');
    }
}
