<?php

namespace Slipstream\Tests\Feature;

use Livewire\Livewire;
use Slipstream\Deploy\MigrationCoordinator;
use Slipstream\Livewire\SettingsPage;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Tests\TestCase;

/**
 * Where a deploy's settings come from. Webhook mode keeps the dashboard's
 * values; poll mode takes them from config and the shared .env only, so a
 * node with an empty database deploys exactly like an old one.
 */
class SettingsPrecedenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(SettingsRepository::class);
        $settings->set('branch', 'from-dashboard');
        $settings->set('keep_releases', '3');
        $settings->set('shared_paths', ['public/dashboard' => 'shared/dashboard']);

        config()->set('slipstream.deploy.branch', 'main');
        config()->set('slipstream.deploy.keep_releases', 7);
        config()->set('slipstream.deploy.shared_paths', ['public/uploads' => 'shared/uploads']);
    }

    public function test_webhook_mode_keeps_the_dashboard_values(): void
    {
        $settings = new SettingsRepository;

        $this->assertSame('from-dashboard', $settings->branch());
        $this->assertSame(3, $settings->keepReleases());
        $this->assertSame(['public/dashboard' => 'shared/dashboard'], $settings->sharedPaths());
    }

    public function test_poll_mode_takes_deploy_settings_from_config_only(): void
    {
        config()->set('slipstream.trigger.mode', 'poll');
        config()->set('slipstream.trigger.ref', 'refs/heads/production');

        $settings = new SettingsRepository;

        $this->assertSame('production', $settings->branch());
        $this->assertSame(7, $settings->keepReleases());
        $this->assertSame(['public/uploads' => 'shared/uploads'], $settings->sharedPaths());

        // A tag ref has no branch of its own; branch deploys use the config one.
        config()->set('slipstream.trigger.ref', 'v*');
        $this->assertSame('main', (new SettingsRepository)->branch());
    }

    public function test_the_repository_and_notification_targets_from_the_environment_win_when_set(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('repo_url', 'git@github.com:org/from-dashboard.git');
        $settings->set('notification_mail', 'dashboard@example.com');

        $this->assertSame('git@github.com:org/from-dashboard.git', $settings->repoUrl());
        $this->assertSame('dashboard@example.com', $settings->notification('mail'));

        config()->set('slipstream.trigger.repository', 'git@github.com:org/vault.git');
        config()->set('slipstream.notifications.mail', 'ops@example.com');
        config()->set('slipstream.notifications.on_success', 'false');

        $this->assertSame('git@github.com:org/vault.git', $settings->repoUrl());
        $this->assertSame('ops@example.com', $settings->notification('mail'));
        $this->assertSame('false', $settings->notification('on_success'));
    }

    public function test_a_malformed_deploy_ref_is_reported_and_leaves_everything_readable(): void
    {
        config()->set('slipstream.trigger.mode', 'poll');
        config()->set('slipstream.trigger.ref', 'not a ref');

        $this->assertSame('main', (new SettingsRepository)->branch(), 'falls back to the deploy branch');

        $this->artisan('slipstream:status')
            ->expectsOutputToContain('invalid ref')
            ->assertExitCode(0);
    }

    public function test_the_dashboard_shows_what_the_environment_decides_read_only_and_saving_leaves_it_alone(): void
    {
        Slipstream::auth(fn () => true);
        config()->set('slipstream.trigger.mode', 'poll');
        config()->set('slipstream.trigger.ref', 'refs/heads/production');
        config()->set('slipstream.notifications.mail', 'ops@example.com');

        Livewire::test(SettingsPage::class)
            ->assertSet('branch', 'production')
            ->assertSet('keep_releases', 7)
            ->assertSet('notification_mail', 'ops@example.com')
            ->assertSee('Poll mode.')
            ->assertSee('SLIPSTREAM_NOTIFY_MAIL')
            ->set('notification_slack_url', 'https://hooks.slack.com/services/x')
            ->call('save')
            ->assertHasNoErrors();

        $stored = new SettingsRepository;
        $this->assertSame('from-dashboard', $stored->get('branch'), 'the deploy settings are config\'s in poll mode');
        $this->assertSame('3', (string) $stored->get('keep_releases'));
        $this->assertSame(['public/dashboard' => 'shared/dashboard'], $stored->get('shared_paths'));
        $this->assertNull($stored->get('notification_mail'), 'an environment value is not copied into the database');
        $this->assertSame('https://hooks.slack.com/services/x', $stored->get('notification_slack_url'));
    }

    public function test_the_dashboard_in_webhook_mode_edits_everything_as_before(): void
    {
        Slipstream::auth(fn () => true);

        Livewire::test(SettingsPage::class)
            ->assertSet('branch', 'from-dashboard')
            ->assertDontSee('Poll mode.')
            ->assertDontSee('Set by')
            ->set('branch', 'release')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('release', (new SettingsRepository)->get('branch'));
    }

    public function test_run_migrations_from_the_environment_wins_over_the_registry(): void
    {
        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false, 'run_migrations' => true]);

        $this->assertTrue(MigrationCoordinator::enabledHere());

        config()->set('slipstream.migrations.enabled', 'false');
        $this->assertFalse(MigrationCoordinator::enabledHere());
    }

    public function test_poll_mode_ignores_a_run_migrations_value_only_an_old_node_has(): void
    {
        // An old vault node opted out with `servers set --run-migrations=0`; a
        // fresh instance has no row to say so. In poll mode they agree.
        config()->set('slipstream.trigger.mode', 'poll');
        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false, 'run_migrations' => false]);

        $this->assertTrue(MigrationCoordinator::enabledHere());

        config()->set('slipstream.migrations.enabled', 'false');
        $this->assertFalse(MigrationCoordinator::enabledHere(), 'SLIPSTREAM_RUN_MIGRATIONS still decides');

        $this->artisan('slipstream:servers set --run-migrations=0')
            ->expectsOutputToContain('has no effect while poll mode is on')
            ->assertExitCode(0);
    }
}
