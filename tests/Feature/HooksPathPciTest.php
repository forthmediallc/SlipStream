<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\TestCase;

/**
 * PCI mode with a hooks path of its own: the provider hook is the one route,
 * under the hooks path; the dashboard and the old path do not exist.
 */
class HooksPathPciTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('slipstream.pci.enabled', true);
        $app['config']->set('slipstream.hooks_path', '_hooks');
    }

    public function test_pci_mode_registers_only_the_deploy_hook_under_the_hooks_path(): void
    {
        app(SettingsRepository::class)->set('webhook_secret', 'pci-secret', encrypted: true);

        $this->assertNotSame(404, $this->post('/_hooks/deploy', [], ['Content-Type' => 'application/json'])->status());

        foreach (['/_hooks/relay', '/_hooks/env-sync', '/_hooks/deploy-sync', '/slipstream', '/slipstream/hooks/deploy'] as $path) {
            $this->post($path)->assertNotFound();
        }
    }
}
