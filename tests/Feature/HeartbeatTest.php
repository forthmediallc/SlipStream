<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\DefaultHooks;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentHook;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
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

    public function test_heartbeat_fails_deploys_stuck_in_building(): void
    {
        $stuck = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'status' => Deployment::STATUS_BUILDING,
            'started_at' => now()->subHours(5),
        ]);

        $fresh = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'status' => Deployment::STATUS_BUILDING,
            'started_at' => now()->subMinutes(2),
        ]);

        $this->artisan('slipstream:heartbeat')->assertExitCode(0);

        $this->assertSame(Deployment::STATUS_FAILED, $stuck->fresh()->status);
        $this->assertStringContainsString('watchdog', $stuck->fresh()->failure_reason);
        $this->assertSame(Deployment::STATUS_BUILDING, $fresh->fresh()->status, 'a running deploy must be left alone');
    }

    /**
     * An install upgraded to 0.5.2 still holds the earlier "Build assets"
     * text; install seeds only what is missing, so the heartbeat brings the
     * untouched copy up to date — once, keyed on a setting.
     */
    public function test_the_heartbeat_upgrades_earlier_default_hooks_once(): void
    {
        DefaultHooks::seed();
        $old = DefaultHooks::PREVIOUS['Build assets'][0];
        DeploymentHook::query()->where('name', 'Build assets')->update(['script' => $old, 'enabled' => false]);

        $this->artisan('slipstream:heartbeat')
            ->expectsOutputToContain('1 brought up to date')
            ->assertExitCode(0);

        $hook = DeploymentHook::query()->where('name', 'Build assets')->sole();
        $this->assertStringStartsWith("set -e\n", $hook->script);
        $this->assertFalse($hook->enabled, 'only the text changes');
        $this->assertSame((string) DefaultHooks::VERSION, app(SettingsRepository::class)->get(DefaultHooks::VERSION_SETTING));

        // The old text put back by hand: the version flag is the only trigger.
        DeploymentHook::query()->where('name', 'Build assets')->update(['script' => $old]);
        $this->artisan('slipstream:heartbeat')->assertExitCode(0);
        $this->assertSame($old, DeploymentHook::query()->where('name', 'Build assets')->sole()->script);
    }
}
