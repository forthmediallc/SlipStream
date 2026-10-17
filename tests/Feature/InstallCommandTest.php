<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\ActivityLog;
use Slipstream\Models\DeploymentHook;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_install_seeds_pipeline_server_and_secret(): void
    {
        $this->artisan('slipstream:install')->assertExitCode(0);

        $this->assertTrue(Pipeline::query()->where('name', 'main')->exists());
        $this->assertTrue(Server::query()->where('server_name', 'test-node')->exists());
        $this->assertNotNull(app(SettingsRepository::class)->webhookSecret());
    }

    public function test_pci_install_turns_the_flag_on_in_the_shared_env(): void
    {
        $root = $this->envRoot("APP_KEY=x\nSLIPSTREAM_PCI_MODE=false\nAPP_ENV=production\n");

        $this->artisan('slipstream:install --pci')
            ->expectsOutputToContain('SLIPSTREAM_PCI_MODE=true written')
            ->expectsOutputToContain('the only route registered in PCI mode')
            ->assertExitCode(0);

        // The existing line is replaced in place, nothing else moves, and the
        // defaults a deploy needs are still seeded.
        $this->assertSame("APP_KEY=x\nSLIPSTREAM_PCI_MODE=true\nAPP_ENV=production\n", file_get_contents($root.'/.env'));
        $this->assertTrue(Pipeline::query()->where('name', 'main')->exists());
        $this->assertTrue(DeploymentHook::query()->exists());
        $this->assertSame(1, ActivityLog::query()->where('action', 'pci.enabled')->count());

        $this->removeTree($root);
    }

    public function test_pci_install_appends_the_flag_and_is_idempotent(): void
    {
        $root = $this->envRoot("APP_KEY=x\n");

        $this->artisan('slipstream:install --pci')->assertExitCode(0);
        $this->artisan('slipstream:install --pci')
            ->expectsOutputToContain('already on')
            ->assertExitCode(0);

        $this->assertSame("APP_KEY=x\nSLIPSTREAM_PCI_MODE=true\n", file_get_contents($root.'/.env'));
        $this->assertSame(1, ActivityLog::query()->where('action', 'pci.enabled')->count(), 'a no-op re-run is not an event');

        $this->removeTree($root);
    }

    public function test_a_plain_install_leaves_the_env_alone(): void
    {
        $root = $this->envRoot("APP_KEY=x\n");

        $this->artisan('slipstream:install')
            ->expectsOutputToContain('Dashboard:')
            ->assertExitCode(0);

        $this->assertSame("APP_KEY=x\n", file_get_contents($root.'/.env'));

        $this->removeTree($root);
    }

    protected function envRoot(string $contents): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-install-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        file_put_contents($root.'/.env', $contents);
        config()->set('slipstream.app_root', $root);

        return $root;
    }

    public function test_fresh_install_gives_the_fleet_its_own_signing_key(): void
    {
        $this->artisan('slipstream:install')->assertExitCode(0);

        $settings = app(SettingsRepository::class);

        // A new install must never sign peer traffic with the secret it hands to
        // the git host.
        $this->assertTrue($settings->hasSeparatePeerSecret());
        $this->assertNotSame($settings->webhookSecret(), $settings->peerSecret());
    }

    public function test_reinstall_over_an_existing_fleet_does_not_split_the_key_behind_your_back(): void
    {
        // Simulates upgrading a fleet installed before the split: peers still
        // running the old code sign with the webhook secret, so generating a
        // fleet key here would start rejecting them mid-upgrade.
        app(SettingsRepository::class)->ensureWebhookSecret();

        $this->artisan('slipstream:install')->assertExitCode(0);

        $this->assertFalse(app(SettingsRepository::class)->hasSeparatePeerSecret());
    }

    public function test_install_is_idempotent(): void
    {
        $this->artisan('slipstream:install')->assertExitCode(0);
        $firstSecret = app(SettingsRepository::class)->webhookSecret();

        $this->artisan('slipstream:install')->assertExitCode(0);

        $this->assertSame(1, Pipeline::query()->where('name', 'main')->count());
        $this->assertSame($firstSecret, app(SettingsRepository::class)->webhookSecret(), 'secret must not rotate on re-install');
    }
}
