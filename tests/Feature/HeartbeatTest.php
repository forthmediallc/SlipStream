<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Fleet\Heartbeat;
use Slipstream\Models\Server;
use Slipstream\Tests\TestCase;

class HeartbeatTest extends TestCase
{
    public function test_pulse_registers_this_node(): void
    {
        app(Heartbeat::class)->pulse();

        $server = Server::query()->where('server_name', 'test-node')->first();
        $this->assertNotNull($server);
        $this->assertSame(PHP_VERSION, $server->php_version);
        $this->assertNotNull($server->last_heartbeat_at);
    }

    public function test_service_status_is_null_when_package_not_installed(): void
    {
        app(Heartbeat::class)->pulse();

        $server = Server::query()->where('server_name', 'test-node')->first();

        // Horizon/Reverb aren't installed in the test app, so status is unknown
        // (null → "—" in the UI), not a misleading "down".
        $this->assertNull($server->horizon_running);
        $this->assertNull($server->reverb_running);
    }
}
