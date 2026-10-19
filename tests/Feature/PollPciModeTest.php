<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\TestCase;

/**
 * The vault configuration: PCI mode with the poll trigger. Slipstream adds
 * no inbound endpoint of any kind.
 */
class PollPciModeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('slipstream.pci.enabled', true);
        $app['config']->set('slipstream.trigger.mode', 'poll');
    }

    public function test_slipstream_registers_no_route_at_all(): void
    {
        $ours = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'slipstream'))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $ours);
        $this->postJson('/slipstream/hooks/deploy', [])->assertNotFound();
    }

    public function test_install_needs_no_webhook_secret_in_poll_mode(): void
    {
        $this->artisan('slipstream:install')
            ->expectsOutputToContain('poll · branch main (no webhook route)')
            ->assertExitCode(0);

        $this->assertNull(app(SettingsRepository::class)->webhookSecret());
    }
}
