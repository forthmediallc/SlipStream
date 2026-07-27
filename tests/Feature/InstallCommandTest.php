<?php

namespace Slipstream\Tests\Feature;

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
