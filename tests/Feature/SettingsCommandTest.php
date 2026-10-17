<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\ActivityLog;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\TestCase;

class SettingsCommandTest extends TestCase
{
    public function test_set_writes_each_option_given_and_show_reads_it_back(): void
    {
        $this->artisan('slipstream:settings set --repo=git@github.com:org/app.git --branch=develop --keep-releases=3 --shared-path=public/uploads:shared/uploads --shared-path="storage/app/private:shared/private" --notify-success=0 --mail=ops@example.com --slack-url=https://hooks.slack.com/services/x --relay=on')
            ->expectsOutputToContain('Settings saved.')
            ->assertExitCode(0);

        $settings = app(SettingsRepository::class);
        $this->assertSame('git@github.com:org/app.git', $settings->repoUrl());
        $this->assertSame('develop', $settings->branch());
        $this->assertSame(3, $settings->keepReleases());
        $this->assertSame(['public/uploads' => 'shared/uploads', 'storage/app/private' => 'shared/private'], $settings->sharedPaths());
        $this->assertSame('0', $settings->get('notify_on_success'));
        $this->assertSame('ops@example.com', $settings->get('notification_mail'));
        $this->assertSame('https://hooks.slack.com/services/x', $settings->get('notification_slack_url'));
        $this->assertSame('1', $settings->get('relay_enabled'));
        $this->assertTrue(ActivityLog::query()->where('action', 'webhook.relay_enabled')->exists());

        $this->artisan('slipstream:settings')
            ->expectsOutputToContain('git@github.com:org/app.git')
            ->expectsOutputToContain('public/uploads → shared/uploads')
            ->doesntExpectOutputToContain('hooks.slack.com')
            ->assertExitCode(0);

        // The JSON document is one console write, so one substring per run.
        $this->artisan('slipstream:settings show --json')
            ->expectsOutputToContain('"notification_slack_url_set": true')
            ->doesntExpectOutputToContain('hooks.slack.com')
            ->assertExitCode(0);
        $this->artisan('slipstream:settings show --json')
            ->expectsOutputToContain('"relay_enabled": true')
            ->assertExitCode(0);
    }

    public function test_clearing_and_validation(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('shared_paths', ['a' => 'b']);
        $settings->set('notification_mail', 'x@example.com');

        $this->artisan('slipstream:settings set --clear-shared-paths --mail=""')->assertExitCode(0);
        $this->assertNull($settings->get('shared_paths'));
        $this->assertNull($settings->get('notification_mail'));

        $this->artisan('slipstream:settings set --branch="-oops"')
            ->expectsOutputToContain('--branch:')
            ->assertExitCode(1);

        $this->artisan('slipstream:settings set --keep-releases=0')
            ->expectsOutputToContain('1 to 50')
            ->assertExitCode(1);

        $this->artisan('slipstream:settings set --shared-path=../etc:shared')
            ->expectsOutputToContain('must not contain')
            ->assertExitCode(1);

        $this->artisan('slipstream:settings set --mail=not-an-address')
            ->expectsOutputToContain('email address')
            ->assertExitCode(1);

        $this->artisan('slipstream:settings set')
            ->expectsOutputToContain('Nothing to set')
            ->assertExitCode(1);
    }

    public function test_relay_on_a_pci_node_is_stored_but_called_out(): void
    {
        config()->set('slipstream.pci.enabled', true);

        $this->artisan('slipstream:settings set --relay=on')
            ->expectsOutputToContain('never fans out')
            ->assertExitCode(0);

        $this->artisan('slipstream:settings show --json')
            ->expectsOutputToContain('"relay_effective": false')
            ->assertExitCode(0);
    }
}
