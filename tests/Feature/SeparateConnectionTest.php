<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Slipstream\Database\Tables;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Environment\EnvSync;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

/**
 * SLIPSTREAM_DB_CONNECTION set from the start: the migrations, every model and
 * every "does this table exist" check use Slipstream's connection, and the
 * app's database receives nothing but the migrator's record that they ran.
 */
class SeparateConnectionTest extends TestCase
{
    protected string $secret = 'separate-connection-secret';

    protected FakeProcessDetacher $detacher;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // In place before the migrations run in setUp(), so they create the
        // tables here rather than on the default connection.
        $app['config']->set('database.connections.slipstream_store', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('slipstream.database.connection', 'slipstream_store');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);
    }

    protected function tearDown(): void
    {
        // Testbench rolls the migrations back on Slipstream's connection.
        if ($this->app) {
            config()->set('slipstream.database.connection', 'slipstream_store');
        }

        parent::tearDown();
    }

    public function test_the_migrations_create_every_table_on_slipstreams_connection_only(): void
    {
        $tables = app(Tables::class);

        $this->assertSame(Tables::ALL, $tables->presentOn('slipstream_store'));
        $this->assertSame([], $tables->presentOn('testing'));

        // The migrator's own record of what ran stays with the app.
        $this->assertTrue(Schema::connection('testing')->hasTable('migrations'));
        $this->assertFalse(Schema::connection('slipstream_store')->hasTable('migrations'));
    }

    public function test_a_signed_push_records_its_delivery_and_deployment_there(): void
    {
        app(SettingsRepository::class)->set('webhook_secret', $this->secret, encrypted: true);
        Pipeline::query()->create([
            'name' => 'main', 'branch_pattern' => 'main', 'auto_deploy' => true, 'enabled' => true, 'server_names' => null,
        ]);

        $this->postGithubPush('d-1')->assertOk();

        $store = DB::connection('slipstream_store');
        $this->assertSame(1, $store->table('slipstream_deployments')->count());
        $this->assertSame('accepted', $store->table('slipstream_webhook_deliveries')->value('outcome'));
        $this->assertSame(1, $store->table('slipstream_settings')->where('key', 'webhook_secret')->count());
        $this->assertSame(1, Deployment::query()->count(), 'the models read the same connection');
        $this->assertContains('slipstream:deploy', array_map(fn ($c) => $c['args'][0], $this->detacher->calls));
    }

    public function test_the_heartbeat_and_the_table_checks_follow_the_connection(): void
    {
        app(Heartbeat::class)->pulse();

        $this->assertSame(1, DB::connection('slipstream_store')->table('slipstream_servers')->count());

        // Both ask Slipstream's connection whether their tables exist; asking
        // the app's would say no and quietly switch the features off.
        $this->assertTrue(EnvSync::enabled());
        app(SettingsRepository::class)->set('branch', 'develop');
        $this->assertSame('develop', (new SettingsRepository)->branch());
    }

    public function test_migrate_fresh_on_the_app_database_leaves_slipstreams_tables_alone(): void
    {
        app(SettingsRepository::class)->set('webhook_secret', $this->secret, encrypted: true);

        // The app's record of what ran is wiped and every migration runs
        // again; the guarded creates find Slipstream's tables already there.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $this->assertSame($this->secret, (new SettingsRepository)->webhookSecret());
        $this->assertSame([], app(Tables::class)->presentOn('testing'));
    }

    public function test_install_status_and_doctor_report_the_connection(): void
    {
        // Bullet lists render through Termwind, which collapses the aligned
        // spacing after the label, so match on the connection itself.
        $this->artisan('slipstream:install')
            ->expectsOutputToContain('slipstream_store (sqlite: :memory:)')
            ->assertExitCode(0);

        $this->assertSame(1, DB::connection('slipstream_store')->table('slipstream_pipelines')->count());
        $this->assertSame([], app(Tables::class)->presentOn('testing'));

        $this->artisan('slipstream:status')
            ->expectsOutputToContain('separate from the app database')
            ->assertExitCode(0);

        $this->withScratchRoot(function () {
            $this->artisan('slipstream:doctor')
                ->expectsOutputToContain('separate from the app database')
                ->assertExitCode(1); // the bare layout fails elsewhere
        });
    }

    public function test_install_and_doctor_name_a_connection_that_is_not_defined(): void
    {
        config()->set('slipstream.database.connection', 'nowhere');

        $this->artisan('slipstream:install')
            ->expectsOutputToContain('which config/database.php does not define')
            ->assertExitCode(1);

        $this->withScratchRoot(function () {
            $this->artisan('slipstream:doctor')
                ->expectsOutputToContain('which config/database.php does not define')
                ->assertExitCode(1);
        });
    }

    protected function withScratchRoot(callable $run): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-sepconn-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        config()->set('slipstream.app_root', $root);
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        try {
            $run();
        } finally {
            $this->removeTree($root);
        }
    }

    protected function postGithubPush(string $deliveryId)
    {
        $sha = str_repeat('a', 40);
        $body = json_encode([
            'ref' => 'refs/heads/main',
            'after' => $sha,
            'head_commit' => ['id' => $sha, 'message' => 'msg', 'author' => ['name' => 'Dev']],
            'repository' => ['full_name' => 'org/app'],
            'pusher' => ['name' => 'dev'],
        ], JSON_THROW_ON_ERROR);

        $server = $this->transformHeadersToServerVars([
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => $deliveryId,
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', '/slipstream/hooks/deploy', [], [], [], $server, $body);
    }
}
