<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\ActivityLog;
use Slipstream\Models\Server;
use Slipstream\Tests\TestCase;

/**
 * Instances an Auto Scaling group terminated never say goodbye. The daily
 * prune forgets them once they have been silent long enough — when asked to.
 */
class ForgetSilentServersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false, 'last_heartbeat_at' => now()->subHours(100)]);
        Server::query()->create(['server_name' => 'terminated', 'enabled' => true, 'paused' => false, 'last_heartbeat_at' => now()->subHours(50)]);
        Server::query()->create(['server_name' => 'alive', 'enabled' => true, 'paused' => false, 'last_heartbeat_at' => now()->subHour()]);
        Server::query()->create(['server_name' => 'never-beat', 'enabled' => true, 'paused' => false])
            ->forceFill(['created_at' => now()->subHours(50)])->save();
    }

    public function test_nothing_is_forgotten_unless_asked(): void
    {
        $this->artisan('slipstream:prune')->assertExitCode(0);

        $this->assertSame(4, Server::query()->count());
    }

    public function test_long_silent_servers_are_forgotten_but_never_this_node(): void
    {
        config()->set('slipstream.fleet.forget_after_hours', 48);

        $this->artisan('slipstream:prune')
            ->expectsOutputToContain('Servers forgotten (silent > 48h)')
            ->assertExitCode(0);

        $this->assertEqualsCanonicalizing(['test-node', 'alive'], Server::query()->pluck('server_name')->all());
        $this->assertSame(2, ActivityLog::query()->where('action', 'server.forgotten')->count());
    }
}
