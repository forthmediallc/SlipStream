<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\ActivityLog;
use Slipstream\Models\DeploymentHook;
use Slipstream\Models\Server;
use Slipstream\Tests\TestCase;

class HooksCommandTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false]);
        Server::query()->create(['server_name' => 'peer-1', 'enabled' => true, 'paused' => false]);

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-hooks-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function test_add_creates_an_enabled_hook_after_the_step(): void
    {
        $this->artisan('slipstream:hooks add --step=composer --name="Build assets" --script="{{ npm }} run build"')
            ->expectsOutputToContain('added: after composer')
            ->assertExitCode(0);

        $hook = DeploymentHook::query()->sole();
        $this->assertSame('composer', $hook->step);
        $this->assertSame('after', $hook->position);
        $this->assertSame('Build assets', $hook->name);
        $this->assertSame('{{ npm }} run build', $hook->script);
        $this->assertTrue($hook->enabled);
        $this->assertFalse($hook->fleet_lock);
        $this->assertNull($hook->server_names);
        $this->assertSame(10, $hook->sort_order);
        $this->assertTrue(ActivityLog::query()->where('action', 'hook.added')->exists());
    }

    public function test_add_reads_a_script_file_and_scopes_servers_and_fleet_lock(): void
    {
        file_put_contents($this->dir.'/migrate.sh', "{{ php }} artisan migrate --force\r\n{{ php }} artisan db:seed --force\r\n");

        // Array form: a Windows path's backslashes do not survive the
        // string tokenizer.
        $this->artisan('slipstream:hooks', [
            'action' => 'add',
            '--step' => 'composer',
            '--position' => 'before',
            '--name' => 'Migrate',
            '--script-file' => $this->dir.'/migrate.sh',
            '--servers' => 'test-node,peer-1',
            '--fleet-lock' => '1',
        ])->assertExitCode(0);

        $hook = DeploymentHook::query()->sole();
        $this->assertSame('before', $hook->position);
        $this->assertSame("{{ php }} artisan migrate --force\n{{ php }} artisan db:seed --force", $hook->script);
        $this->assertSame(['test-node', 'peer-1'], $hook->server_names);
        $this->assertTrue($hook->fleet_lock);
    }

    public function test_add_rejects_an_unknown_step_and_a_missing_script(): void
    {
        $this->artisan('slipstream:hooks add --step=teleport --name=x --script=y')
            ->expectsOutputToContain('Unknown step')
            ->assertExitCode(1);

        $this->artisan('slipstream:hooks add --step=composer --name=x')
            ->expectsOutputToContain('A script is required')
            ->assertExitCode(1);

        $this->assertSame(0, DeploymentHook::query()->count());
    }

    public function test_edit_changes_only_what_was_given(): void
    {
        $hook = DeploymentHook::query()->create(['step' => 'composer', 'position' => 'after', 'name' => 'Old', 'script' => 'old', 'enabled' => true, 'sort_order' => 10, 'server_names' => ['peer-1']]);

        $this->artisan("slipstream:hooks edit {$hook->id} --name=New --fleet-lock=1")->assertExitCode(0);

        $hook->refresh();
        $this->assertSame('New', $hook->name);
        $this->assertSame('old', $hook->script);
        $this->assertSame(['peer-1'], $hook->server_names, 'servers untouched when not given');
        $this->assertTrue($hook->fleet_lock);

        $this->artisan("slipstream:hooks edit {$hook->id} --servers=all --script=\"new script\"")->assertExitCode(0);

        $hook->refresh();
        $this->assertNull($hook->server_names);
        $this->assertSame('new script', $hook->script);
    }

    public function test_enable_disable_and_move(): void
    {
        $first = DeploymentHook::query()->create(['step' => 'composer', 'position' => 'after', 'name' => 'First', 'script' => 'a', 'enabled' => true, 'sort_order' => 10]);
        $second = DeploymentHook::query()->create(['step' => 'composer', 'position' => 'after', 'name' => 'Second', 'script' => 'b', 'enabled' => true, 'sort_order' => 20]);

        $this->artisan("slipstream:hooks disable {$first->id}")->assertExitCode(0);
        $this->assertFalse($first->fresh()->enabled);

        $this->artisan("slipstream:hooks enable {$first->id}")->assertExitCode(0);
        $this->assertTrue($first->fresh()->enabled);

        $this->artisan("slipstream:hooks move {$second->id} up")
            ->expectsOutputToContain('now before #'.$first->id)
            ->assertExitCode(0);
        $this->assertTrue($second->fresh()->sort_order < $first->fresh()->sort_order);

        $this->artisan("slipstream:hooks move {$second->id} up")
            ->expectsOutputToContain('already first')
            ->assertExitCode(0);

        $this->artisan("slipstream:hooks move {$second->id} sideways")
            ->expectsOutputToContain('up or down')
            ->assertExitCode(1);
    }

    public function test_delete_asks_unless_forced(): void
    {
        $hook = DeploymentHook::query()->create(['step' => 'fpm', 'position' => 'after', 'name' => 'Restart', 'script' => 'x', 'enabled' => true, 'sort_order' => 10]);

        $this->artisan("slipstream:hooks delete {$hook->id}")
            ->expectsConfirmation("Delete hook #{$hook->id} \"Restart\"?", 'no')
            ->expectsOutput('Kept.')
            ->assertExitCode(0);
        $this->assertTrue(DeploymentHook::query()->whereKey($hook->id)->exists());

        $this->artisan("slipstream:hooks delete {$hook->id} --force")->assertExitCode(0);
        $this->assertFalse(DeploymentHook::query()->whereKey($hook->id)->exists());
        $this->assertTrue(ActivityLog::query()->where('action', 'hook.deleted')->exists());
    }

    public function test_list_and_show_and_json(): void
    {
        $hook = DeploymentHook::query()->create(['step' => 'swap', 'position' => 'before', 'name' => 'Warm cache', 'script' => "line one\nline two", 'enabled' => true, 'sort_order' => 10]);

        $this->artisan('slipstream:hooks')
            ->expectsOutputToContain('Warm cache')
            ->assertExitCode(0);

        $this->artisan("slipstream:hooks show {$hook->id}")
            ->expectsOutputToContain('before swap')
            ->expectsOutputToContain('line two')
            ->assertExitCode(0);

        $this->artisan('slipstream:hooks list --json')
            ->expectsOutputToContain('"name": "Warm cache"')
            ->assertExitCode(0);

        $this->artisan('slipstream:hooks show 9999')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        $this->artisan('slipstream:hooks frobnicate')
            ->expectsOutputToContain('Unknown action')
            ->assertExitCode(1);
    }

    public function test_adding_a_multi_line_script_without_set_e_warns(): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'build.sh', "npm run build\nrm -rf node_modules\n");

        $this->artisan('slipstream:hooks', ['action' => 'add', '--step' => 'composer', '--name' => 'Build docs', '--script-file' => $this->dir.DIRECTORY_SEPARATOR.'build.sh'])
            ->expectsOutputToContain('no `set -e`')
            ->assertExitCode(0);
    }
}
