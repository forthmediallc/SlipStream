<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Deploy\Deployer;
use Slipstream\Deploy\HookFile;
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

    public function test_a_fleet_locked_hook_holds_its_lock_as_long_as_the_hook_may_run(): void
    {
        DefaultHooks::seed();
        $hook = DeploymentHook::query()->where('name', DefaultHooks::MIGRATE_HOOK)->sole();
        $deployer = $this->deployer();

        // The hook may run an hour; the 900 s TTL used to lapse well before.
        $deployer->runner->during = function (string $command) use ($hook) {
            if (str_contains($command, 'artisan migrate --force')) {
                $this->travel(1000)->seconds();
                $this->assertFalse(Cache::lock(Deployer::fleetLockKey($hook, self::SHA), 10)->get(), 'still held');
            }
        };

        $deployer->hooks('composer', 'after');

        $migrate = array_values(array_filter($deployer->runner->commands, fn ($c) => str_contains($c['command'], 'artisan migrate --force')));
        $this->assertCount(1, $migrate);
        $this->assertSame(Deployer::HOOK_TIMEOUT, $migrate[0]['timeout']);
    }

    public function test_the_migrate_step_runs_its_command_under_the_migrate_timeout(): void
    {
        config()->set('slipstream.migrations.command', 'migrate --force');
        config()->set('slipstream.deploy.migrate_timeout', 1200);

        $deployer = $this->deployer()->migrate();

        $migrate = array_values(array_filter($deployer->runner->commands, fn ($c) => str_contains($c['command'], 'artisan migrate --force')));
        $this->assertSame(1200, $migrate[0]['timeout']);
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
            "<?php return [['name' => 'x', 'step' => 'composer', 'script' => 'y', 'fail_fast' => 'no']];" => 'fail_fast must be true or false',
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

    public function test_the_seeded_build_hook_runs_under_set_e(): void
    {
        DefaultHooks::seed();

        $build = array_values(array_filter(
            $this->deployer()->hooks('composer', 'after')->commands(),
            fn ($c) => str_contains($c, 'run build')
        ));

        $this->assertCount(1, $build);
        $this->assertStringStartsWith("set -e\n", $build[0]);
    }

    /**
     * An install upgraded to 0.5.2 whose heartbeat has not run yet still
     * holds the 0.5.1 text of "Build assets": still a default, still steps
     * aside for the file.
     */
    public function test_an_earlier_untouched_default_still_steps_aside_for_the_file(): void
    {
        DefaultHooks::seed();
        DeploymentHook::query()->where('name', 'Build assets')->update(['script' => DefaultHooks::PREVIOUS['Build assets'][0]]);
        $this->writeHooks($this->vaultHooks());

        $commands = $this->deployer()->hooks('composer', 'after')->commands();

        $this->assertSame([], array_filter($commands, fn ($c) => str_contains($c, 'run build')));
    }

    public function test_a_multi_line_file_hook_fails_fast_unless_it_opts_out(): void
    {
        $this->writeHooks(<<<'PHP'
<?php

return [
    ['name' => 'Two lines', 'step' => 'composer', 'script' => "npm run build\nrm -rf node_modules"],
    ['name' => 'Opted out', 'step' => 'composer', 'script' => "npm run build\nrm -rf node_modules", 'fail_fast' => false],
    ['name' => 'One line', 'step' => 'composer', 'script' => 'npm run build'],
    ['name' => 'Already', 'step' => 'composer', 'script' => "set -eu\nnpm run build\nrm -rf node_modules"],
];
PHP);

        $this->assertSame([
            "set -e\nnpm run build\nrm -rf node_modules",
            "npm run build\nrm -rf node_modules",
            'npm run build',
            "set -eu\nnpm run build\nrm -rf node_modules",
        ], $this->deployer()->hooks('composer', 'after')->commands());
    }

    public function test_where_defaults_to_node_and_must_be_node_or_build_on_a_build_step(): void
    {
        $this->writeHooks(<<<'PHP'
<?php

return [
    ['name' => 'Build', 'step' => 'composer', 'where' => 'build', 'script' => 'echo build'],
    ['name' => 'Warm', 'step' => 'swap', 'position' => 'before', 'script' => 'echo warm'],
];
PHP);
        $hooks = HookFile::load($this->release);
        $this->assertSame(['build', 'node'], [$hooks[0]->where, $hooks[1]->where]);

        foreach ([
            "<?php return [['name' => 'x', 'step' => 'composer', 'where' => 'ci', 'script' => 'y']];" => 'where must be node or build',
            "<?php return [['name' => 'x', 'step' => 'swap', 'where' => 'build', 'script' => 'y']];" => 'can only attach to fetch or composer; swap happens on the node',
        ] as $file => $message) {
            $this->writeHooks($file);
            try {
                HookFile::load($this->release);
                $this->fail("accepted a hooks file that should say: {$message}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_build_time_hooks_are_skipped_on_an_artifact_node_and_node_time_hooks_run(): void
    {
        $this->writeHooks(<<<'PHP'
<?php

return [
    ['name' => 'Build', 'step' => 'composer', 'where' => 'build', 'script' => '{{ npm }} run build'],
    ['name' => 'Warm', 'step' => 'swap', 'position' => 'before', 'script' => 'echo warm'],
];
PHP);

        // Git mode: the node is the builder and runs both.
        $commands = $this->deployer()->hooks('composer', 'after')->hooks('swap', 'before')->commands();
        $this->assertSame(['npm run build', 'echo warm'], $commands);

        // Artifact mode: the build ran in CI; a stray build tool fails loudly.
        config()->set('slipstream.release.source', 'artifact');
        $commands = $this->deployer()->hooks('composer', 'after')->hooks('swap', 'before')->commands();
        $this->assertCount(1, $commands);
        $this->assertSame(Deployer::ARTIFACT_GUARD."\necho warm", $commands[0]);
        $this->assertStringContainsString('npm does not run on an artifact node', Deployer::ARTIFACT_GUARD);
    }

    public function test_an_untouched_build_assets_default_is_build_time_and_an_edited_one_is_not(): void
    {
        DefaultHooks::seed();
        $assets = DeploymentHook::query()->where('name', 'Build assets')->sole();
        $this->assertSame('build', DefaultHooks::whereOf($assets));
        $this->assertSame('node', DefaultHooks::whereOf(DeploymentHook::query()->where('name', 'Optimize (cache config, routes, views)')->sole()));

        $this->artisan('slipstream:hooks list --json')
            ->expectsOutputToContain('"where": "build"')
            ->assertExitCode(0);

        config()->set('slipstream.release.source', 'artifact');
        $this->assertSame([], array_filter($this->deployer()->hooks('composer', 'after')->commands(), fn ($c) => str_contains($c, 'run build')), 'the stored default never builds on an artifact node');

        $assets->update(['script' => "set -e\nnpm run build:custom"]);
        $this->assertSame('node', DefaultHooks::whereOf($assets->fresh()), 'edited: the operator owns it now');
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
