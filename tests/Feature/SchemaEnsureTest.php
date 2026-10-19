<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Slipstream\Database\Tables;
use Slipstream\Tests\TestCase;

/**
 * Per-node Slipstream databases with a shared app database: the app's
 * migrations table records a Slipstream migration as run the first time any
 * node runs it, so every other node has to bring its own schema current.
 */
class SchemaEnsureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.node', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('slipstream.database.connection', 'node');
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            config()->set('slipstream.database.connection', 'node');
        }

        parent::tearDown();
    }

    public function test_a_node_missing_a_migration_another_node_ran_brings_itself_current(): void
    {
        // What this node looks like when a release added a table and a column,
        // and the shared migrations table already says another node ran them.
        Schema::connection('node')->drop('slipstream_env_snapshots');
        Schema::connection('node')->table('slipstream_servers', fn (Blueprint $t) => $t->dropColumn('poll_seen_sha'));
        DB::connection('node')->table('slipstream_settings')->where('key', Tables::SCHEMA_KEY)->delete();
        $this->assertTrue(
            DB::table('migrations')->where('migration', '2024_01_01_000008_add_poll_state_to_servers')->exists(),
            '`migrate` has nothing left to run here'
        );

        $this->artisan('slipstream:heartbeat')
            ->expectsOutputToContain('brought current')
            ->assertExitCode(0);

        $this->assertTrue(Schema::connection('node')->hasTable('slipstream_env_snapshots'));
        $this->assertTrue(Schema::connection('node')->hasColumn('slipstream_servers', 'poll_seen_sha'));
    }

    public function test_a_current_schema_is_a_cheap_no_op_and_every_migration_runs_twice_safely(): void
    {
        $tables = app(Tables::class);

        $tables->ensureCurrent('node');
        $this->assertFalse($tables->ensureCurrent('node'), 'the stored signature short-circuits');

        $tables->createOn('node');
        $tables->createOn('node');
        $this->assertSame(Tables::ALL, $tables->presentOn('node'));
    }

    public function test_slipstream_database_ensure_does_the_same_by_hand(): void
    {
        DB::connection('node')->table('slipstream_settings')->where('key', Tables::SCHEMA_KEY)->delete();

        $this->artisan('slipstream:database ensure')
            ->expectsOutputToContain("Brought Slipstream's schema on node current.")
            ->assertExitCode(0);

        $this->artisan('slipstream:database ensure')
            ->expectsOutputToContain("Slipstream's schema on node is current.")
            ->assertExitCode(0);
    }

    public function test_a_node_on_the_app_database_leaves_its_schema_to_migrate(): void
    {
        config()->set('slipstream.database.connection', null);

        $this->assertFalse(app(Tables::class)->hasOwnConnection());
        $this->assertFalse(app(Tables::class)->ensureCurrentIfOwnConnection());
    }
}
