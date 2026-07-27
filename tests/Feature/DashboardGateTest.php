<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Slipstream\Http\Middleware\AuthorizeSlipstream;
use Slipstream\Livewire\SettingsPage;
use Slipstream\Livewire\SlipstreamComponent;
use Slipstream\Slipstream;
use Slipstream\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DashboardGateTest extends TestCase
{
    public function test_dashboard_is_denied_by_default_outside_local(): void
    {
        // Environment is "testing" (not local) with no auth callback → denied.
        $this->get('/slipstream/webhook')->assertStatus(403);
    }

    public function test_dashboard_allowed_when_auth_callback_passes(): void
    {
        Slipstream::auth(fn () => true);

        $this->get('/slipstream/webhook')->assertOk()->assertSee('Webhook');
    }

    public function test_host_defined_view_slipstream_gate_is_honored(): void
    {
        Gate::define('viewSlipstream', fn ($user = null) => true);

        $this->get('/slipstream/webhook')->assertOk();
    }

    public function test_local_fallback_allows_the_machine_itself(): void
    {
        $this->app['env'] = 'local';

        $this->get('/slipstream/webhook')->assertOk();
    }

    public function test_local_fallback_denies_a_remote_origin(): void
    {
        // `php artisan serve --host=0.0.0.0`, a published container port, a
        // colleague on the same LAN: "local environment" is not a fence, and the
        // dashboard hands out shell, the shared .env, and the webhook secret.
        $this->app['env'] = 'local';

        $this->call('GET', '/slipstream/webhook', [], [], [], ['REMOTE_ADDR' => '192.168.1.50'])
            ->assertStatus(403);
    }

    public function test_local_fallback_denies_a_forwarded_request(): void
    {
        // A share tunnel (ngrok / expose / `herd share`) connects FROM loopback,
        // so the forwarding header is the only tell.
        $this->app['env'] = 'local';

        $this->call(
            'GET',
            '/slipstream/webhook',
            [], [], [],
            $this->transformHeadersToServerVars(['X-Forwarded-For' => '203.0.113.9'])
        )->assertStatus(403);
    }

    public function test_local_open_opt_in_restores_open_access(): void
    {
        $this->app['env'] = 'local';
        config()->set('slipstream.local_open', true);

        $this->call('GET', '/slipstream/webhook', [], [], [], ['REMOTE_ADDR' => '192.168.1.50'])
            ->assertOk();
    }

    public function test_local_fallback_never_applies_outside_local(): void
    {
        // Loopback in production must still be denied — the fallback is scoped
        // to `local`, the loopback check only narrows it further.
        $this->call('GET', '/slipstream/webhook', [], [], [], ['REMOTE_ADDR' => '127.0.0.1'])
            ->assertStatus(403);
    }

    public function test_every_dashboard_component_extends_the_gated_base(): void
    {
        $components = [
            \Slipstream\Livewire\ProjectChrome::class,
            \Slipstream\Livewire\DeploymentsIndex::class,
            \Slipstream\Livewire\DeploymentShow::class,
            \Slipstream\Livewire\WebhookPage::class,
            \Slipstream\Livewire\SettingsPage::class,
            \Slipstream\Livewire\ServersPage::class,
            \Slipstream\Livewire\PipelinesPage::class,
            \Slipstream\Livewire\DeployHooksPage::class,
            \Slipstream\Livewire\EnvironmentPage::class,
        ];

        foreach ($components as $component) {
            $this->assertTrue(
                is_subclass_of($component, SlipstreamComponent::class),
                "{$component} must extend the gated base component"
            );
        }
    }

    public function test_authorize_middleware_is_registered_as_livewire_persistent(): void
    {
        // The core of the fix: the gate persists onto POST /livewire/update so
        // component actions can't be driven from a captured snapshot.
        $this->assertContains(AuthorizeSlipstream::class, Livewire::getPersistentMiddleware());
    }

    public function test_component_boot_hook_aborts_when_gate_denies(): void
    {
        Slipstream::auth(fn () => false);

        $component = new SettingsPage;

        $this->expectException(HttpException::class);

        // Defence-in-depth: boot() runs on every Livewire request and re-checks.
        $component->boot();
    }
}
