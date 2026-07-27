<?php

namespace Slipstream\Tests\Feature;

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Livewire\DeployHooksPage;
use Slipstream\Livewire\DeploymentsIndex;
use Slipstream\Livewire\ProjectChrome;
use Slipstream\Livewire\ServersPage;
use Slipstream\Livewire\SettingsPage;
use Slipstream\Livewire\WebhookPage;
use Slipstream\Models\ActivityLog;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

class DashboardActionsTest extends TestCase
{
    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        Slipstream::auth(fn () => true);
        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);
    }

    public function test_deploy_now_spawns_and_records_activity(): void
    {
        Livewire::test(DeploymentsIndex::class)->call('deployNow');

        $this->assertSame(1, Deployment::query()->count());
        $this->assertCount(1, $this->detacher->calls);
        $this->assertDatabaseHas('slipstream_activity_log', ['action' => 'deploy.started']);
    }

    public function test_project_chrome_deploy_modal_spawns_and_records_activity(): void
    {
        Livewire::test(ProjectChrome::class)
            ->call('openDeployModal')
            ->assertSet('showDeployModal', true)
            ->call('confirmDeploy')
            ->assertSet('showDeployModal', false);

        $this->assertSame(1, Deployment::query()->count());
        $deployment = Deployment::query()->first();
        $this->assertSame('default', $deployment->meta['deploy_from'] ?? null);
        $this->assertCount(1, $this->detacher->calls);
        $this->assertDatabaseHas('slipstream_activity_log', ['action' => 'deploy.started']);
    }

    public function test_project_chrome_deploy_can_target_a_branch_and_skip_hooks(): void
    {
        $hook = \Slipstream\Models\DeploymentHook::query()->create([
            'step' => 'composer',
            'position' => 'after',
            'name' => 'Build assets',
            'script' => 'echo build',
            'enabled' => true,
            'sort_order' => 1,
        ]);
        $keep = \Slipstream\Models\DeploymentHook::query()->create([
            'step' => 'composer',
            'position' => 'after',
            'name' => 'Run migrations',
            'script' => 'echo migrate',
            'enabled' => true,
            'sort_order' => 2,
        ]);

        Livewire::test(ProjectChrome::class)
            ->call('openDeployModal')
            ->set('deployFrom', 'branch')
            ->set('customBranch', 'feature/xyz')
            ->set('selectedHookIds', [$keep->id])
            ->call('confirmDeploy');

        $deployment = Deployment::query()->first();
        $this->assertSame('feature/xyz', $deployment->branch);
        $this->assertSame('branch', $deployment->meta['deploy_from']);
        $this->assertSame([(int) $hook->id], $deployment->meta['skip_hook_ids']);
        $this->assertSame([(int) $hook->id], $deployment->skippedHookIds());
    }

    public function test_redeploy_creates_a_fresh_deployment_for_the_same_sha(): void
    {
        $source = Deployment::query()->create([
            'server_name' => 'test-node',
            'sha' => str_repeat('c', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_DEPLOYED,
        ]);

        Livewire::test(DeploymentsIndex::class)->call('redeploy', $source->id);

        $this->assertSame(2, Deployment::query()->count());
        $fresh = Deployment::query()->latest('id')->first();
        $this->assertSame($source->sha, $fresh->sha);
        $this->assertSame(Deployment::STATUS_PENDING, $fresh->status);
        $this->assertDatabaseHas('slipstream_activity_log', ['action' => 'deploy.redeploy']);
    }

    public function test_test_ping_runs_through_real_verification_and_passes(): void
    {
        Livewire::test(WebhookPage::class)
            ->call('sendTestPing')
            ->assertHasNoErrors();

        $log = ActivityLog::query()->where('action', 'webhook.test_ping')->first();
        $this->assertNotNull($log, 'test ping should record activity');
        $this->assertSame(200, (int) data_get($log->meta, 'status'), 'signed ping should verify (HTTP 200)');
    }

    public function test_reveal_flag_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        // A crafted client update trying to force reveal must be rejected.
        Livewire::test(WebhookPage::class)->set('revealSecret', true);
    }

    public function test_reveal_action_exposes_secret_server_side(): void
    {
        Livewire::test(WebhookPage::class)
            ->call('reveal')
            ->assertSet('revealSecret', true);
    }

    public function test_deploy_modal_refuses_a_ref_that_git_would_read_as_an_option(): void
    {
        Livewire::test(ProjectChrome::class)
            ->call('openDeployModal')
            ->set('deployFrom', 'tag')
            ->set('customTag', '--upload-pack=touch /tmp/pwned')
            ->call('confirmDeploy')
            ->assertHasErrors('customTag');

        $this->assertSame(0, Deployment::query()->count(), 'a hostile ref must never reach a deployment row');
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_deploy_modal_accepts_an_ordinary_tag(): void
    {
        Livewire::test(ProjectChrome::class)
            ->call('openDeployModal')
            ->set('deployFrom', 'tag')
            ->set('customTag', '  v1.4.2  ')
            ->call('confirmDeploy')
            ->assertHasNoErrors();

        $this->assertSame('v1.4.2', Deployment::query()->latest('id')->first()->branch, 'ref should be trimmed, not rejected');
    }

    public function test_settings_refuses_an_option_shaped_branch(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('branch', '--exec=whoami')
            ->call('save')
            ->assertHasErrors('branch');
    }

    public function test_fleet_key_can_be_separated_from_the_webhook_secret(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->ensureWebhookSecret();
        $this->assertFalse($settings->hasSeparatePeerSecret(), 'starts on the legacy shared secret');

        Livewire::test(WebhookPage::class)
            ->call('rotatePeerSecret')
            ->assertHasNoErrors();

        $fresh = app(SettingsRepository::class);
        $this->assertTrue($fresh->hasSeparatePeerSecret());
        $this->assertNotSame($fresh->webhookSecret(), $fresh->peerSecret());
        $this->assertDatabaseHas('slipstream_activity_log', ['action' => 'webhook.fleet_key_separated']);
    }

    public function test_hook_can_be_added_edited_and_deleted_via_deploy_hooks_page(): void
    {
        Livewire::test(DeployHooksPage::class)
            ->call('newHook')
            ->set('hook_step', 'composer')
            ->set('hook_position', 'after')
            ->set('hook_name', 'Warm cache')
            ->set('hook_script', 'php artisan app:warm')
            ->call('saveHook');

        $this->assertDatabaseHas('slipstream_deployment_hooks', ['name' => 'Warm cache', 'step' => 'composer']);
        $hook = \Slipstream\Models\DeploymentHook::query()->first();

        Livewire::test(DeployHooksPage::class)->call('editHook', $hook->id)->set('hook_name', 'Warm cache v2')->call('saveHook');
        $this->assertDatabaseHas('slipstream_deployment_hooks', ['name' => 'Warm cache v2']);

        Livewire::test(DeployHooksPage::class)->call('deleteHook', $hook->id);
        $this->assertDatabaseMissing('slipstream_deployment_hooks', ['id' => $hook->id]);
    }

    public function test_hook_fleet_lock_is_persisted_from_the_form(): void
    {
        Livewire::test(DeployHooksPage::class)
            ->call('newHook')
            ->set('hook_step', 'composer')
            ->set('hook_position', 'after')
            ->set('hook_name', 'Run migrations')
            ->set('hook_script', 'php artisan migrate --force')
            ->set('hook_fleet_lock', true)
            ->call('saveHook');

        $this->assertDatabaseHas('slipstream_deployment_hooks', [
            'name' => 'Run migrations',
            'fleet_lock' => true,
        ]);
    }

    public function test_hook_can_be_toggled_off_and_on(): void
    {
        $hook = \Slipstream\Models\DeploymentHook::query()->create([
            'step' => 'composer', 'position' => 'after', 'name' => 'Build assets',
            'script' => 'npm run build', 'enabled' => true, 'sort_order' => 10,
        ]);

        Livewire::test(DeployHooksPage::class)->call('toggleHook', $hook->id);
        $this->assertFalse($hook->fresh()->enabled);
        $this->assertDatabaseHas('slipstream_activity_log', ['action' => 'hook.disabled']);

        Livewire::test(DeployHooksPage::class)->call('toggleHook', $hook->id);
        $this->assertTrue($hook->fresh()->enabled);
    }

    public function test_server_removal_and_toggle_are_audited(): void
    {
        $peer = Server::query()->create([
            'server_name' => 'peer-x', 'enabled' => true, 'paused' => false,
        ]);

        Livewire::test(ServersPage::class)->call('toggleEnabled', $peer->id);
        $this->assertDatabaseHas('slipstream_activity_log', ['action' => 'server.disabled']);

        Livewire::test(ServersPage::class)->call('remove', $peer->id);
        $this->assertDatabaseHas('slipstream_activity_log', ['action' => 'server.removed']);
    }

    public function test_server_removal_cleans_up_env_pushes_and_snapshot(): void
    {
        $peer = Server::query()->create([
            'server_name' => 'peer-cleanup', 'enabled' => true, 'paused' => false,
        ]);
        \Slipstream\Models\EnvSnapshot::query()->create([
            'server_name' => 'peer-cleanup', 'contents' => "A=1\n",
            'hash' => 'h', 'captured_at' => now(),
        ]);
        \Slipstream\Models\EnvPush::query()->create([
            'batch' => 'b', 'server_name' => 'peer-cleanup', 'contents' => "A=1\n",
            'preserve_keys' => true, 'status' => \Slipstream\Models\EnvPush::STATUS_PENDING,
        ]);

        Livewire::test(ServersPage::class)->call('remove', $peer->id);

        $this->assertDatabaseMissing('slipstream_env_snapshots', ['server_name' => 'peer-cleanup']);
        $this->assertDatabaseMissing('slipstream_env_pushes', ['server_name' => 'peer-cleanup']);
    }
}
