<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Slipstream\Database\Tables;
use Slipstream\Deploy\DeploymentRunner;
use Slipstream\Deploy\HealthGate;
use Slipstream\Fleet\LastGood;
use Slipstream\Models\ActivityLog;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Release\DeployRef;
use Slipstream\Release\ReleaseSource;
use Slipstream\Tests\Support\FakeDeploymentRunner;
use Slipstream\Tests\Support\FakeHealthGate;
use Slipstream\Tests\Support\FakeReleaseSource;
use Slipstream\Tests\TestCase;

/**
 * A replacement instance: an empty per-node SQLite file that does not even
 * exist yet, the shared .env, and nothing else. Git, composer and the health
 * gate are stood in for; what is under test is everything around them.
 */
class BootstrapCommandTest extends TestCase
{
    protected const GOOD = '1111111111111111111111111111111111111111';

    protected const TAGGED = '2222222222222222222222222222222222222222';

    protected string $root;

    protected FakeReleaseSource $source;

    protected FakeDeploymentRunner $runner;

    protected FakeHealthGate $health;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('slipstream.trigger.mode', 'poll');
        $app['config']->set('slipstream.trigger.ref', 'v*');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-bootstrap-'.bin2hex(random_bytes(4));
        mkdir($this->root.DIRECTORY_SEPARATOR.'repo.git', 0777, true);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'.env', "APP_KEY=x\n");
        config()->set('slipstream.app_root', $this->root);

        // The node's own database: a file that does not exist yet.
        config()->set('database.connections.node', [
            'driver' => 'sqlite',
            'database' => $this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'slipstream.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('slipstream.database.connection', 'node');

        $this->source = (new FakeReleaseSource)->at(self::TAGGED, 'v1.2.0', tag: true);
        $this->app->instance(ReleaseSource::class, $this->source);
        $this->runner = new FakeDeploymentRunner($this->root);
        $this->app->instance(DeploymentRunner::class, $this->runner);
        $this->health = new FakeHealthGate;
        $this->app->instance(HealthGate::class, $this->health);
    }

    protected function tearDown(): void
    {
        // Testbench rolls its migrations back on Slipstream's connection; the
        // default one is where they ran. Let go of the SQLite file first.
        if ($this->app) {
            config()->set('slipstream.database.connection', null);
            DB::purge('node');
        }

        parent::tearDown();

        $this->removeTree($this->root);
    }

    public function test_a_fresh_instance_comes_up_serving_the_deploy_ref(): void
    {
        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('Ready: serving 2222222.')
            ->assertExitCode(0);

        $this->assertSame(Tables::ALL, app(Tables::class)->presentOn('node'), 'the empty database got every table');
        $this->assertTrue(Server::query()->where('server_name', 'test-node')->exists());

        $deployment = Deployment::query()->sole();
        $this->assertSame(Deployment::TRIGGER_BOOTSTRAP, $deployment->trigger);
        $this->assertSame([self::TAGGED, 'v1.2.0'], [$deployment->sha, $deployment->branch]);
        $this->assertSame(['deploy_from' => 'tag', 'poll_ref' => 'tags:v*'], $deployment->meta);
        $this->assertSame(self::TAGGED, $this->running());
        $this->assertCount(1, $this->health->checked, 'the health gate ran on what is live');
        $this->assertTrue(ActivityLog::query()->where('action', 'bootstrap.finished')->exists());
    }

    public function test_bootstrap_is_idempotent(): void
    {
        $this->artisan('slipstream:bootstrap')->assertExitCode(0);

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('already serving 2222222')
            ->assertExitCode(0);

        $this->assertCount(1, $this->runner->ran, 'no second deploy');
        $this->assertSame(1, Deployment::query()->count());
        $this->assertCount(2, $this->health->checked, 'the health gate runs every time');
    }

    public function test_a_broken_ref_falls_back_to_the_fleets_last_good_commit(): void
    {
        $this->runner->failing = [self::TAGGED];
        app(LastGood::class)->record(DeployRef::parse('v*'), self::GOOD, 'v1.1.0');

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('Falling back to the fleet\'s last good commit 1111111 (v1.1.0')
            ->expectsOutputToContain('Ready: serving 1111111.')
            ->assertExitCode(0);

        $this->assertSame(self::GOOD, $this->running());
        $this->assertSame(
            [Deployment::STATUS_FAILED, Deployment::STATUS_DEPLOYED],
            Deployment::query()->orderBy('id')->pluck('status')->all()
        );
        $this->assertNull(Deployment::query()->latest('id')->first()->meta['poll_ref'] ?? null, 'a fallback is not the ref');
        $this->assertTrue(ActivityLog::query()->where('action', 'bootstrap.fell_back')->exists());
    }

    public function test_without_a_good_commit_or_with_no_fallback_it_exits_non_zero(): void
    {
        $this->runner->failing = [self::TAGGED];

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('no other good commit to fall back to')
            ->assertExitCode(1);

        app(LastGood::class)->record(DeployRef::parse('v*'), self::GOOD, 'v1.1.0');

        $this->artisan('slipstream:bootstrap --no-fallback')->assertExitCode(1);
        $this->assertNotSame(self::GOOD, $this->running(), '--no-fallback means no fallback');
    }

    public function test_a_failing_health_check_keeps_the_instance_out(): void
    {
        $this->health->ok = false;

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('The health check failed on the live release.')
            ->assertExitCode(1);
    }

    public function test_it_needs_the_shared_env_and_something_to_mirror(): void
    {
        unlink($this->root.DIRECTORY_SEPARATOR.'.env');
        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('No shared .env')
            ->assertExitCode(1);

        file_put_contents($this->root.DIRECTORY_SEPARATOR.'.env', "APP_KEY=x\n");
        rmdir($this->root.DIRECTORY_SEPARATOR.'repo.git');
        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('no git mirror and no repository')
            ->assertExitCode(1);

        $this->assertSame([], $this->runner->ran);
    }

    protected function running(): ?string
    {
        $file = $this->root.DIRECTORY_SEPARATOR.'current'.DIRECTORY_SEPARATOR.'RELEASE_SHA';

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }
}
