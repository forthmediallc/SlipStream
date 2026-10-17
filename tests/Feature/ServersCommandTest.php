<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\ActivityLog;
use Slipstream\Models\EnvPush;
use Slipstream\Models\EnvSnapshot;
use Slipstream\Models\Server;
use Slipstream\Tests\TestCase;

class ServersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false, 'last_heartbeat_at' => now(), 'current_sha' => str_repeat('a', 40)]);
        Server::query()->create(['server_name' => 'peer-1', 'enabled' => true, 'paused' => false, 'last_heartbeat_at' => now()->subHour(), 'current_sha' => str_repeat('a', 40), 'base_url' => 'https://peer1.example']);
    }

    public function test_list_shows_the_fleet_and_who_is_offline(): void
    {
        $this->artisan('slipstream:servers')
            ->expectsOutputToContain('test-node (this)')
            ->expectsOutputToContain('https://peer1.example')
            ->expectsOutputToContain('Offline: peer-1')
            ->assertExitCode(0);

        $this->artisan('slipstream:servers list --json')
            ->expectsOutputToContain('"offline": [')
            ->assertExitCode(0);
    }

    public function test_pause_and_resume_default_to_this_node(): void
    {
        $this->artisan('slipstream:servers pause')
            ->expectsOutputToContain('test-node paused')
            ->assertExitCode(0);
        $this->assertTrue(Server::query()->where('server_name', 'test-node')->value('paused') == true);

        $this->artisan('slipstream:servers resume')->assertExitCode(0);
        $this->assertFalse((bool) Server::query()->where('server_name', 'test-node')->value('paused'));

        $this->artisan('slipstream:servers disable peer-1')->assertExitCode(0);
        $this->assertFalse((bool) Server::query()->where('server_name', 'peer-1')->value('enabled'));
        $this->assertTrue(ActivityLog::query()->where('action', 'server.disabled')->exists());
    }

    public function test_set_changes_the_per_server_settings(): void
    {
        $this->artisan('slipstream:servers set peer-1 --base-url=https://peer1.example/ --run-migrations=0 --fpm-service=php8.3-fpm')
            ->expectsOutputToContain('Settings saved for peer-1')
            ->assertExitCode(0);

        $peer = Server::query()->where('server_name', 'peer-1')->first();
        $this->assertSame('https://peer1.example', $peer->base_url, 'trailing slash trimmed');
        $this->assertFalse((bool) $peer->run_migrations);
        $this->assertSame('php8.3-fpm', $peer->fpm_service);

        $this->artisan('slipstream:servers set peer-1 --base-url=""')->assertExitCode(0);
        $this->assertNull($peer->fresh()->base_url);

        $this->artisan('slipstream:servers set peer-1 --base-url=not-a-url')
            ->expectsOutputToContain('full origin')
            ->assertExitCode(1);

        $this->artisan('slipstream:servers set peer-1')
            ->expectsOutputToContain('Nothing to set')
            ->assertExitCode(1);
    }

    public function test_forget_removes_a_peer_and_its_env_rows_but_never_this_node(): void
    {
        EnvSnapshot::query()->create(['server_name' => 'peer-1', 'contents' => 'A=1', 'hash' => 'h', 'captured_at' => now()]);
        EnvPush::query()->create(['batch' => 'b', 'server_name' => 'peer-1', 'contents' => 'A=1', 'preserve_keys' => true, 'status' => EnvPush::STATUS_PENDING]);

        $this->artisan('slipstream:servers forget test-node --force')
            ->expectsOutputToContain('Cannot forget this node')
            ->assertExitCode(1);

        $this->artisan('slipstream:servers forget peer-1')
            ->expectsConfirmation('Forget peer-1? Its env snapshot and queued pushes go with it.', 'yes')
            ->assertExitCode(0);

        $this->assertFalse(Server::query()->where('server_name', 'peer-1')->exists());
        $this->assertSame(0, EnvSnapshot::query()->count());
        $this->assertSame(0, EnvPush::query()->count());

        $this->artisan('slipstream:servers pause ghost')
            ->expectsOutputToContain('No server named "ghost"')
            ->assertExitCode(1);
    }
}
