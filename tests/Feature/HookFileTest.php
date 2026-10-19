<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Deploy\Deployer;
use Slipstream\Deploy\MigrationCoordinator;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentHook;
use Slipstream\Models\Server;
use Slipstream\Tests\Support\RecordingDeployer;
use Slipstream\Tests\TestCase;

/**
 * Hooks the release carries, read from the release being deployed, so a
 * node with an empty database runs exactly what an old one runs.
 */
class HookFileTest extends TestCase
{
    protected const SHA = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    protected string $root;

    protected string $release;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-hookfile-'.bin2hex(random_bytes(4));
        $this->release = $this->root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'20261018_000000_eeeeeee';
        mkdir($this->release, 0777, true);
        config()->set('slipstream.app_root', $this->root);

        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_file_defined_hooks_run_on_a_fresh_database(): void
    {
        $this->writeHooks($this->vaultHooks());
        $this->assertSame(0, DeploymentHook::query()->count(), 'a brand-new node has no hooks of its own');

        $commands = $this->deployer()->hooks('composer', 'after')->hooks('swap', 'before')->commands();

        $this->assertCount(2, $commands);
        $this->assertStringEndsWith('artisan vault:migrate --force --if-vault', $commands[0]);
        $this->assertSame('echo warm', $commands[1]);
    }

    public function test_a_fresh_database_runs_exactly_what_an_old_one_runs(): void
    {
        $this->writeHooks($this->vaultHooks());

        $fresh = $this->deployer()->hooks('composer', 'after')->commands();

        // An old node: install seeded the defaults long ago, and nobody touched them.
        DefaultHooks::seed();
        $old = $this->deployer()->hooks('composer', 'after')->commands();

        $this->assertSame($fresh, $old);
    }

    public function test_the_file_replaces_untouched_defaults_and_database_hooks_add_to_it(): void
    {
        DefaultHooks::seed();
        DeploymentHook::query()->where('name', 'Optimize (cache config, routes, views)')
            ->update(['script' => '{{ php }} artisan optimize --except=events']);
        DeploymentHook::query()->create([
            'step' => 'composer', 'position' => 'after', 'name' => 'Notify', 'script' => 'echo notify',
            'enabled' => true, 'sort_order' => 100,
        ]);
        $this->writeHooks("<?php return [['name' => 'From the file', 'step' => 'composer', 'script' => 'echo from-file']];");

        $commands = $this->deployer()->hooks('composer', 'after')->commands();

        $this->assertSame('echo from-file', $commands[0], 'file hooks run first');
        $this->assertStringEndsWith('artisan optimize --except=events', $commands[1], 'an edited default is the operator\'s hook now');
        $this->assertSame('echo notify', $commands[2], 'database hooks add to the file');
        $this->assertCount(3, $commands, 'untouched defaults (assets, migrate, storage link) step aside');
    }

    public function test_without_a_file_the_database_hooks_run_as_before(): void
    {
        DefaultHooks::seed();

        $commands = implode("\n", $this->deployer()->hooks('composer', 'after')->commands());

        $this->assertStringContainsString('artisan migrate --force', $commands);
        $this->assertStringContainsString('artisan optimize', $commands);
    }

    public function test_the_migrate_step_takes_over_from_the_seeded_migrations_hook_once_per_commit(): void
    {
        config()->set('slipstream.migrations.command', 'vault:migrate --force --if-vault');
        DefaultHooks::seed();

        $here = $this->deployer()->hooks('composer', 'after')->migrate()->commands();
        $this->assertSame(1, count(array_filter($here, fn ($c) => str_contains($c, 'artisan vault:migrate --force --if-vault'))));
        $this->assertSame([], array_filter($here, fn ($c) => str_ends_with($c, 'artisan migrate --force')), 'the seeded hook stepped aside');

        // Another node deploying the same commit finds it migrated.
        config()->set('slipstream.server_name', 'peer-2');
        $there = $this->deployer('peer-2')->migrate()->commands();
        $this->assertSame([], array_filter($there, fn ($c) => str_contains($c, 'vault:migrate')));
    }

    public function test_a_node_set_not_to_migrate_skips_the_step(): void
    {
        config()->set('slipstream.migrations.command', 'migrate --force');
        config()->set('slipstream.migrations.enabled', 'false');

        $this->assertSame([], $this->deployer()->migrate()->commands());
    }

    public function test_a_node_set_not_to_migrate_will_not_swap_onto_a_failed_migration(): void
    {
        config()->set('slipstream.migrations.command', 'migrate --force');
        config()->set('slipstream.migrations.enabled', 'false');
        Cache::put(MigrationCoordinator::markerKey(self::SHA), ['status' => 'failed', 'node' => 'peer-1', 'error' => 'connection refused'], 900);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migrations for eeeeeee failed on peer-1: connection refused');

        $this->deployer()->migrate();
    }

    public function test_a_broken_hooks_file_fails_the_deploy_rather_than_building_something_else(): void
    {
        foreach ([
            "<?php return [['name' => 'x', 'step' => 'teleport', 'script' => 'y']];" => 'unknown step "teleport"',
            "<?php return ['build' => ['name' => 'x', 'step' => 'composer', 'script' => 'y']];" => 'must return a list',
            "<?php return [['step' => 'composer', 'script' => 'y']];" => 'needs a name',
            "<?php return [['name' => 'x', 'step' => 'fetch', 'position' => 'before', 'script' => 'y']];" => 'before-fetch',
            '<?php return [' => 'could not be loaded',
        ] as $file => $message) {
            $this->writeHooks($file);

            try {
                $this->deployer();
                $this->fail("accepted a hooks file that should say: {$message}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_hooks_list_shows_where_each_hook_comes_from(): void
    {
        DefaultHooks::seed();
        $current = $this->root.DIRECTORY_SEPARATOR.'current';
        mkdir($current, 0777, true);
        file_put_contents($current.DIRECTORY_SEPARATOR.'slipstream-hooks.php', $this->vaultHooks());

        $this->artisan('slipstream:hooks')
            ->expectsOutputToContain('Vault migrations')
            ->expectsOutputToContain('skipped: the hooks file replaces the defaults')
            ->expectsOutputToContain('File hooks come from')
            ->assertExitCode(0);

        $this->artisan('slipstream:hooks list --json')
            ->expectsOutputToContain('"source": "file"')
            ->assertExitCode(0);
    }

    public function test_the_fleet_lock_is_named_by_the_hook_not_its_row(): void
    {
        $a = new DeploymentHook(['step' => 'composer', 'position' => 'after', 'name' => 'Run migrations']);
        $a->id = 2;
        $b = new DeploymentHook(['step' => 'composer', 'position' => 'after', 'name' => 'Run migrations']);
        $b->id = 7;

        $this->assertSame(Deployer::fleetLockKey($a, self::SHA), Deployer::fleetLockKey($b, strtoupper(self::SHA)));
    }

    protected function deployer(string $server = 'test-node'): RecordingDeployer
    {
        $deployment = Deployment::query()->create([
            'server_name' => $server, 'sha' => self::SHA, 'branch' => 'production',
            'trigger' => Deployment::TRIGGER_POLL, 'status' => Deployment::STATUS_BUILDING,
        ]);

        return (new RecordingDeployer($deployment))->prepare($this->release, self::SHA);
    }

    protected function writeHooks(string $php): void
    {
        file_put_contents($this->release.DIRECTORY_SEPARATOR.'slipstream-hooks.php', $php);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->release.DIRECTORY_SEPARATOR.'slipstream-hooks.php', true);
        }
    }

    protected function vaultHooks(): string
    {
        return <<<'PHP'
<?php

return [
    ['name' => 'Vault migrations', 'step' => 'composer', 'script' => '{{ php }} artisan vault:migrate --force --if-vault'],
    ['name' => 'Warm cache', 'step' => 'swap', 'position' => 'before', 'script' => 'echo warm'],
];
PHP;
    }
}
