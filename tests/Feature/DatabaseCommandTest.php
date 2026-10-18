<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Slipstream\Database\Tables;
use Slipstream\Diagnostics\FpmPool;
use Slipstream\Models\ActivityLog;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentEvent;
use Slipstream\Models\DeploymentHook;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\Activity;
use Slipstream\Tests\TestCase;

/**
 * Moving an existing install's tables off the app database: copy while the
 * app still runs on the old connection, switch, then drop the old set.
 */
class DatabaseCommandTest extends TestCase
{
    protected string $dir;

    protected string $targetPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A file that does not exist yet, in a directory that does not either:
        // the usual state of a SQLite target on the day of the move.
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-dbmove-'.bin2hex(random_bytes(4));
        $this->targetPath = $this->dir.DIRECTORY_SEPARATOR.'shared'.DIRECTORY_SEPARATOR.'slipstream.sqlite';

        $app['config']->set('database.connections.target', [
            'driver' => 'sqlite',
            'database' => $this->targetPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Testbench rolls the migrations back on Slipstream's connection after
        // each test. Give it the default connection with the tables in place
        // (a test may have dropped them), and close the SQLite file first —
        // Windows will not delete a file a PDO handle still holds.
        if ($this->app) {
            $tables = app(Tables::class);
            config()->set('slipstream.database.connection', null);

            if ($tables->presentOn('testing') === []) {
                $tables->createOn('testing');
            }

            DB::purge('target');
        }

        parent::tearDown();

        $this->removeTree($this->dir);
    }

    public function test_copy_moves_every_row_and_the_secret_still_decrypts_there(): void
    {
        ['deployment' => $deployment] = $this->seedInstall();
        $tables = app(Tables::class);
        $before = $tables->counts('testing');

        $this->artisan('slipstream:database copy --to=target')
            ->expectsOutputToContain('Copied')
            ->expectsOutputToContain('SLIPSTREAM_DB_CONNECTION=target')
            ->assertExitCode(0);

        $this->assertFileExists($this->targetPath, 'the SQLite file and its directory are created');

        $after = $tables->counts('target');
        foreach ($before as $table => $rows) {
            // The move itself is recorded where the history now lives.
            $expected = $table === 'slipstream_activity_log' ? $rows + 1 : $rows;
            $this->assertSame($expected, $after[$table], $table);
        }
        $this->assertSame($before, $tables->counts('testing'), 'a copy leaves the source alone');

        // Ids come across, so references still line up.
        $this->assertSame($deployment->id, (int) DB::connection('target')->table('slipstream_webhook_deliveries')->value('deployment_id'));

        // Switched over, Slipstream reads the copy, encrypted secret included.
        config()->set('slipstream.database.connection', 'target');
        $this->assertSame('the-original-secret', (new SettingsRepository)->webhookSecret());
        $this->assertSame(3, Deployment::query()->findOrFail($deployment->id)->events()->count());
        $this->assertTrue(ActivityLog::query()->where('action', 'database.copied')->exists());

        // New rows continue after the copied ids instead of colliding.
        $next = Deployment::query()->create(['server_name' => 'test-node', 'trigger' => 'manual', 'status' => Deployment::STATUS_PENDING]);
        $this->assertGreaterThan($deployment->id, $next->id);
    }

    public function test_copy_refuses_without_a_target_onto_itself_or_onto_an_unknown_connection(): void
    {
        $this->seedInstall();

        $this->artisan('slipstream:database copy')
            ->expectsOutputToContain('Name the target')
            ->assertExitCode(1);

        $this->artisan('slipstream:database copy --to=testing')
            ->expectsOutputToContain('same database')
            ->assertExitCode(1);

        $this->artisan('slipstream:database copy --to=nowhere')
            ->expectsOutputToContain('is not defined')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->targetPath);
    }

    public function test_copy_refuses_a_target_that_already_holds_rows(): void
    {
        $this->seedInstall();

        $this->artisan('slipstream:database copy --to=target')->assertExitCode(0);

        $this->artisan('slipstream:database copy --to=target')
            ->expectsOutputToContain('already holds')
            ->assertExitCode(1);
    }

    public function test_copy_waits_for_deployments_in_flight_unless_forced(): void
    {
        $this->seedInstall();
        Deployment::query()->create(['server_name' => 'test-node', 'trigger' => 'webhook', 'status' => Deployment::STATUS_BUILDING]);

        $this->artisan('slipstream:database copy --to=target')
            ->expectsOutputToContain('queued, pending or building')
            ->assertExitCode(1);

        $this->artisan('slipstream:database copy --to=target --force')->assertExitCode(0);
    }

    public function test_drop_source_refuses_the_set_slipstream_is_still_reading(): void
    {
        $this->seedInstall();
        $this->artisan('slipstream:database copy --to=target')->assertExitCode(0);

        // Not switched yet: the default connection is the live set.
        $this->artisan('slipstream:database drop-source --force')
            ->expectsOutputToContain('live set')
            ->assertExitCode(1);

        $this->assertNotSame([], app(Tables::class)->presentOn('testing'));
    }

    public function test_drop_source_after_switching_removes_the_old_set(): void
    {
        $this->seedInstall();
        $this->artisan('slipstream:database copy --to=target')->assertExitCode(0);
        config()->set('slipstream.database.connection', 'target');

        $rows = number_format(array_sum(app(Tables::class)->counts('testing')));

        $this->artisan('slipstream:database drop-source')
            ->expectsConfirmation("Drop 12 Slipstream tables from \"testing\"? That deletes {$rows} rows there; \"target\" keeps its copy.", 'no')
            ->expectsOutput('Kept.')
            ->assertExitCode(0);
        $this->assertNotSame([], app(Tables::class)->presentOn('testing'));

        $this->artisan('slipstream:database drop-source --force')
            ->expectsOutputToContain('Dropped 12 Slipstream tables from testing')
            ->assertExitCode(0);

        $this->assertSame([], app(Tables::class)->presentOn('testing'));
        $this->assertTrue(Schema::connection('testing')->hasTable('migrations'), "the migrator's record stays");
        $this->assertSame('the-original-secret', (new SettingsRepository)->webhookSecret());
        $this->assertTrue(ActivityLog::query()->where('action', 'database.source_dropped')->exists());
    }

    public function test_drop_source_refuses_while_the_live_set_is_behind_but_not_over_pruning(): void
    {
        $this->seedInstall();
        $this->artisan('slipstream:database copy --to=target')->assertExitCode(0);
        config()->set('slipstream.database.connection', 'target');
        $events = DB::connection('target')->table('slipstream_deployment_events');

        // The prune deleting the oldest row on the live set is normal…
        $events->where('id', $events->min('id'))->delete();
        // …but the newest row missing means the copy did not finish.
        $newest = DB::connection('target')->table('slipstream_deployment_events')->max('id');
        DB::connection('target')->table('slipstream_deployment_events')->where('id', $newest)->delete();

        $this->artisan('slipstream:database drop-source')
            ->expectsOutputToContain('has not caught up')
            ->assertExitCode(1);
        $this->assertNotSame([], app(Tables::class)->presentOn('testing'));

        // Put the newest row back: one pruned old row alone does not block.
        DB::connection('target')->table('slipstream_deployment_events')->insert(
            (array) DB::connection('testing')->table('slipstream_deployment_events')->where('id', $newest)->first()
        );
        $this->artisan('slipstream:database drop-source --force')->assertExitCode(0);
    }

    public function test_status_shows_where_the_records_are_and_what_is_left_behind(): void
    {
        $this->seedInstall();

        $this->artisan('slipstream:database')
            ->expectsOutputToContain('the app database')
            ->expectsOutputToContain('12/12')
            ->assertExitCode(0);

        $this->artisan('slipstream:database copy --to=target')->assertExitCode(0);
        config()->set('slipstream.database.connection', 'target');

        $this->artisan('slipstream:database')
            ->expectsOutputToContain('still on testing')
            ->assertExitCode(0);
    }

    public function test_install_on_a_switched_connection_points_at_the_copy_first(): void
    {
        $this->seedInstall();
        config()->set('slipstream.database.connection', 'target');

        $this->artisan('slipstream:install')
            ->expectsOutputToContain('which has none yet')
            ->expectsOutputToContain('slipstream:database copy --from=testing --to=target')
            ->assertExitCode(1);

        $this->assertSame(0, array_sum(app(Tables::class)->counts('target')), 'no fresh set to block the copy');
    }

    public function test_install_creates_the_tables_on_a_new_connection_when_the_old_set_is_unused(): void
    {
        // The default connection has the (empty) tables from the migrations,
        // which recorded themselves as run; the new connection has nothing.
        config()->set('slipstream.database.connection', 'target');

        $this->artisan('slipstream:install')
            ->expectsOutputToContain('Created Slipstream')
            ->assertExitCode(0);

        $this->assertSame(Tables::ALL, app(Tables::class)->presentOn('target'));
        $this->assertSame(1, DB::connection('target')->table('slipstream_pipelines')->count());
        $this->assertNotNull((new SettingsRepository)->webhookSecret());
    }

    public function test_doctor_names_the_copy_when_the_new_connection_is_empty(): void
    {
        $this->seedInstall();
        config()->set('slipstream.database.connection', 'target');

        $this->withScratchRoot(function () {
            $this->artisan('slipstream:doctor')
                ->expectsOutputToContain('slipstream:database copy --from=testing --to=target')
                ->assertExitCode(1);
        });

        $this->assertFileDoesNotExist($this->targetPath, 'doctor only looks');
    }

    public function test_doctor_flags_a_pci_node_on_the_app_database_and_a_set_left_behind(): void
    {
        $this->seedInstall();

        config()->set('slipstream.pci.enabled', true);
        $this->withScratchRoot(function () {
            $this->artisan('slipstream:doctor')
                ->expectsOutputToContain("the app's default connection (testing)")
                ->assertExitCode(1);
        });
        config()->set('slipstream.pci.enabled', false);

        $this->artisan('slipstream:database copy --to=target')->assertExitCode(0);
        config()->set('slipstream.database.connection', 'target');

        // As the user that owns the file, so the SQLite permission check
        // (Linux only) passes and the leftover warning is what is reported.
        $this->withScratchRoot(function () {
            $this->artisan($this->doctorAs(FpmPool::currentUser()))
                ->expectsOutputToContain('slipstream:database drop-source --from=testing')
                ->assertExitCode(1);
        });
    }

    /**
     * Webhooks and dashboard deploys write as the pool user, and SQLite
     * writes journal files beside the database: a file or directory that
     * user cannot write fails every delivery the moment it is recorded.
     */
    public function test_doctor_fails_a_sqlite_file_the_pool_user_cannot_write(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || ! function_exists('posix_geteuid')) {
            $this->markTestSkipped('needs POSIX ownership.');
        }
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anything; nothing to prove.');
        }

        $other = null;
        foreach (['nobody', 'daemon', 'www-data'] as $candidate) {
            if ($candidate !== FpmPool::currentUser() && is_array(@posix_getpwnam($candidate))) {
                $other = $candidate;
                break;
            }
        }
        if ($other === null) {
            $this->markTestSkipped('no second user account available.');
        }

        $this->seedInstall();
        $this->artisan('slipstream:database copy --to=target')->assertExitCode(0);
        config()->set('slipstream.database.connection', 'target');

        // Assert on the fix line: the summary column carries dot leaders.
        $this->withScratchRoot(function () use ($other) {
            $this->artisan($this->doctorAs($other))
                ->expectsOutputToContain("sudo chown {$other}:{$other} {$this->targetPath}")
                ->assertExitCode(1);
        });
    }

    protected function doctorAs(?string $poolUser): string
    {
        return 'slipstream:doctor'.($poolUser ? " --fpm-user={$poolUser}" : '');
    }

    /**
     * A small but complete install on the default connection: secret,
     * registry, pipeline, hook, a deployment with its log, the delivery that
     * triggered it, and an activity entry.
     *
     * @return array{deployment: Deployment}
     */
    protected function seedInstall(): array
    {
        $settings = app(SettingsRepository::class);
        $settings->set('webhook_secret', 'the-original-secret', encrypted: true);
        $settings->set('branch', 'main');

        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false]);
        $pipeline = Pipeline::query()->create([
            'name' => 'main', 'branch_pattern' => 'main', 'auto_deploy' => true, 'enabled' => true, 'server_names' => null,
        ]);
        DeploymentHook::query()->create([
            'step' => 'composer', 'position' => 'after', 'name' => 'Build', 'script' => 'echo build', 'enabled' => true, 'sort_order' => 10,
        ]);

        $deployment = Deployment::query()->create([
            'server_name' => 'test-node', 'trigger' => 'webhook', 'status' => Deployment::STATUS_DEPLOYED,
            'sha' => str_repeat('a', 40), 'branch' => 'main', 'pipeline_id' => $pipeline->id,
        ]);
        foreach ([1, 2, 3] as $sequence) {
            DeploymentEvent::query()->create([
                'deployment_id' => $deployment->id, 'sequence' => $sequence, 'type' => 'log',
                'level' => 'info', 'message' => "line {$sequence}", 'created_at' => now(),
            ]);
        }

        WebhookDelivery::query()->create([
            'provider' => 'github', 'server_name' => 'test-node', 'delivery_id' => 'd-1',
            'signature_valid' => true, 'outcome' => 'accepted', 'deployment_id' => $deployment->id,
        ]);
        Activity::log('settings.saved');

        return ['deployment' => $deployment];
    }

    protected function withScratchRoot(callable $run): void
    {
        $root = $this->dir.DIRECTORY_SEPARATOR.'app-'.bin2hex(random_bytes(3));
        mkdir($root, 0777, true);
        config()->set('slipstream.app_root', $root);
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $run();
    }
}
