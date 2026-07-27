<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Environment\EnvSync;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Models\EnvSnapshot;
use Slipstream\Tests\TestCase;

class EnvSnapshotTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/ss-envsnap-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);
        file_put_contents($this->root.'/.env', "APP_ENV=production\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/.env*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_pulse_publishes_this_nodes_snapshot(): void
    {
        app(Heartbeat::class)->pulse();

        $snap = EnvSnapshot::query()->where('server_name', 'test-node')->first();
        $this->assertNotNull($snap);
        $this->assertSame(EnvSync::hashContents("APP_ENV=production\n"), $snap->hash);
        $this->assertSame("APP_ENV=production\n", (string) $snap->contents);
    }

    public function test_unchanged_pulse_does_not_rewrite_the_snapshot(): void
    {
        app(Heartbeat::class)->pulse();
        $first = EnvSnapshot::query()->where('server_name', 'test-node')->first();

        // Backdate so a rewrite would visibly bump updated_at.
        EnvSnapshot::query()->whereKey($first->id)->update(['updated_at' => now()->subMinutes(10)]);

        app(Heartbeat::class)->pulse();

        $again = EnvSnapshot::query()->where('server_name', 'test-node')->first();
        $this->assertTrue($again->updated_at->lt(now()->subMinutes(5)), 'unchanged content must not rewrite the snapshot');
    }

    public function test_changed_env_updates_the_hash_in_place(): void
    {
        app(Heartbeat::class)->pulse();
        file_put_contents($this->root.'/.env', "APP_ENV=production\nADDED=1\n");
        app(Heartbeat::class)->pulse();

        $snap = EnvSnapshot::query()->where('server_name', 'test-node')->first();
        $this->assertSame(EnvSync::hashContents("APP_ENV=production\nADDED=1\n"), $snap->hash);
        $this->assertSame(1, EnvSnapshot::query()->where('server_name', 'test-node')->count());
    }

    public function test_disabled_sync_publishes_nothing(): void
    {
        config()->set('slipstream.environment.sync_enabled', false);

        app(Heartbeat::class)->pulse();

        $this->assertSame(0, EnvSnapshot::query()->count());
    }
}
