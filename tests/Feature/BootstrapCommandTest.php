<?php

namespace Slipstream\Tests\Feature;

use Closure;
use Illuminate\Support\Facades\DB;
use Slipstream\Database\Tables;
use Slipstream\Deploy\DeploymentRunner;
use Slipstream\Deploy\HealthGate;
use Slipstream\Fleet\LastGood;
use Slipstream\Models\ActivityLog;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Release\DeployRef;
use Slipstream\Release\ReleaseRef;
use Slipstream\Release\ReleaseSource;
use Slipstream\Tests\Support\FakeDeploymentRunner;
use Slipstream\Tests\Support\FakeHealthGate;
use Slipstream\Tests\Support\FakeReleaseSource;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

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
        $this->source->containing(self::GOOD);
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

    /**
     * The stub's throwaway clone is the deploy ref, so its HEAD is the very
     * commit bootstrap is about to deploy. That clone is not a live release.
     */
    public function test_a_checkout_whose_head_is_the_refs_commit_is_not_a_live_release(): void
    {
        $git = $this->gitOrSkip();
        $clone = $this->root.DIRECTORY_SEPARATOR.'.bootstrap.test'.DIRECTORY_SEPARATOR.'app';
        mkdir($clone, 0777, true);
        $git($clone, 'init', '-q');
        file_put_contents($clone.DIRECTORY_SEPARATOR.'artisan', "<?php\n");
        $git($clone, 'add', '-A');
        $git($clone, 'commit', '-q', '-m', 'v1.2.0');
        $head = trim($git($clone, 'rev-parse', 'HEAD'));
        $this->source->at($head, 'v1.2.0', tag: true);

        $basePath = $this->app->basePath();
        $this->app->setBasePath($clone);

        try {
            $this->artisan('slipstream:bootstrap')
                ->expectsOutputToContain('Ready: serving '.substr($head, 0, 7).'.')
                ->assertExitCode(0);
        } finally {
            $this->app->setBasePath($basePath);
        }

        $this->assertCount(1, $this->runner->ran, 'it deployed rather than taking the checkout for current');
        $this->assertSame($head, $this->running());
    }

    public function test_a_fresh_instance_drops_a_pause_or_pin_it_inherited_with_its_name(): void
    {
        $tables = app(Tables::class);
        $tables->ensureSqliteFile('node');
        $tables->ensureCurrent('node');
        Server::query()->create([
            'server_name' => 'test-node', 'enabled' => true, 'paused' => true,
            'pinned_sha' => self::GOOD, 'pinned_reason' => 'rollback', 'pinned_at' => now()->subWeek(),
        ]);

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('pause and pin cleared')
            ->assertExitCode(0);

        $server = Server::query()->sole();
        $this->assertFalse($server->paused);
        $this->assertNull($server->pinned_sha);
        $this->assertTrue(ActivityLog::query()->where('action', 'bootstrap.hold_cleared')->exists());
    }

    public function test_the_fallback_must_be_in_the_branchs_history(): void
    {
        config()->set('slipstream.trigger.ref', 'refs/heads/production');
        $this->source->at(self::TAGGED, 'production')->containing();
        $this->runner->failing = [self::TAGGED];
        app(LastGood::class)->record(DeployRef::parse('refs/heads/production'), self::GOOD, 'production');

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('it is not in the history of branch production')
            ->expectsOutputToContain('the deploy ref no longer contains the fleet\'s last good commit')
            ->assertExitCode(1);

        $this->assertCount(1, $this->runner->ran, 'no fallback deploy');
        $this->assertTrue(ActivityLog::query()->where('action', 'bootstrap.fallback_refused')->exists());
    }

    public function test_a_tag_pattern_falls_back_only_to_a_commit_a_matching_tag_points_at(): void
    {
        // The bad release's tag was deleted to roll back; last good still
        // names its commit.
        $this->source->containing(self::TAGGED);
        $this->runner->failing = [self::TAGGED];
        app(LastGood::class)->record(DeployRef::parse('v*'), self::GOOD, 'v1.3.0');

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('no tag matching v* points at it now')
            ->assertExitCode(1);

        $this->assertCount(1, $this->runner->ran);
    }

    public function test_an_exact_tag_has_nothing_else_to_fall_back_to(): void
    {
        config()->set('slipstream.trigger.ref', 'refs/tags/v1.2.0');
        $this->source->containing(self::TAGGED);
        $this->runner->failing = [self::TAGGED];
        app(LastGood::class)->record(DeployRef::parse('refs/tags/v1.2.0'), self::GOOD, 'v1.2.0');

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('tag v1.2.0 points at 2222222, not at it')
            ->assertExitCode(1);

        $this->assertCount(1, $this->runner->ran);
    }

    public function test_a_release_source_that_cannot_vouch_for_a_commit_never_falls_back(): void
    {
        $this->app->instance(ReleaseSource::class, new class($this->source) implements ReleaseSource
        {
            public function __construct(private ReleaseSource $inner) {}

            public function latest(DeployRef $ref): ReleaseRef
            {
                return $this->inner->latest($ref);
            }
        });
        $this->runner->failing = [self::TAGGED];
        app(LastGood::class)->record(DeployRef::parse('v*'), self::GOOD, 'v1.1.0');

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('the release source cannot confirm the deploy ref contains it')
            ->assertExitCode(1);

        $this->assertCount(1, $this->runner->ran);
    }

    public function test_in_artifact_mode_bootstrap_needs_no_mirror_and_never_provisions_one(): void
    {
        rmdir($this->root.DIRECTORY_SEPARATOR.'repo.git');
        config()->set('slipstream.release.source', 'artifact');
        config()->set('slipstream.release.environment', 'production');
        config()->set('slipstream.trigger.ref', 'refs/heads/production');
        $this->source->at(self::TAGGED, 'production');

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain('Ready: serving 2222222.')
            ->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.'repo.git');
        $this->assertSame(self::TAGGED, $this->running());
    }

    /**
     * In artifact mode the fallback is the signed pointer's previous, bound
     * to the manifest it names — no shared-store suggestion needed.
     */
    public function test_in_artifact_mode_the_fallback_is_the_pointers_previous_bound_to_its_manifest(): void
    {
        $this->artifactPointer();
        $this->runner->failing = [self::TAGGED];

        $this->artisan('slipstream:bootstrap')
            ->expectsOutputToContain("Falling back to the pointer's previous release 1111111")
            ->assertExitCode(0);

        $this->assertSame(self::GOOD, $this->running());
        $this->assertNull(app(LastGood::class)->get(DeployRef::fromConfig()), 'no last-good record was needed');
        $this->assertSame(['sequence' => 1000, 'manifest_sha256' => str_repeat('8', 64)], Deployment::query()->where('sha', self::TAGGED)->sole()->meta['pointer']);
        $this->assertSame(['sequence' => 1000, 'manifest_sha256' => str_repeat('9', 64)], Deployment::query()->where('sha', self::GOOD)->sole()->meta['pointer']);
    }

    public function test_in_artifact_mode_no_fallback_stops_at_the_head(): void
    {
        $this->artifactPointer();
        $this->runner->failing = [self::TAGGED];

        $this->artisan('slipstream:bootstrap', ['--no-fallback' => true])
            ->expectsOutputToContain('The deploy of 2222222 failed.')
            ->assertExitCode(1);

        $this->assertSame(0, Deployment::query()->where('sha', self::GOOD)->count());
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

    /**
     * Artifact mode against a pointer on a local disk: the head is TAGGED,
     * its previous GOOD.
     */
    protected function artifactPointer(): void
    {
        rmdir($this->root.DIRECTORY_SEPARATOR.'repo.git');
        config()->set('slipstream.release.source', 'artifact');
        config()->set('slipstream.release.environment', 'production');
        config()->set('slipstream.trigger.ref', 'refs/heads/production');
        config()->set('filesystems.disks.artifacts', ['driver' => 'local', 'root' => $this->root.DIRECTORY_SEPARATOR.'store']);
        config()->set('slipstream.artifacts.disk', 'artifacts');
        config()->set('slipstream.artifacts.prefix', '');

        $pair = \Slipstream\Release\Core\SodiumSigner::generate();
        $signer = \Slipstream\Release\Core\SodiumSigner::fromSpec('sodium:'.$pair['secret'], 'k');
        config()->set('slipstream.artifacts.public_keys', 'k:'.$pair['public']);

        \Illuminate\Support\Facades\Storage::disk('artifacts')->put('refs/heads/production.json', \Slipstream\Release\Core\Envelope::encode([
            'ref' => 'refs/heads/production', 'sha' => self::TAGGED, 'manifest_sha256' => str_repeat('8', 64), 'sequence' => 1000,
            'published_at' => '2026-10-20T00:00:00Z', 'previous' => ['sha' => self::GOOD, 'manifest_sha256' => str_repeat('9', 64)],
            'by' => 'ci', 'environment' => 'production', 'slipstream' => 'v0.6.1',
        ], \Slipstream\Release\Core\Envelope::DOMAIN_POINTER, $signer));

        $this->app->instance(ReleaseSource::class, app(\Slipstream\Release\ArtifactSource::class));
    }

    protected function running(): ?string
    {
        $file = $this->root.DIRECTORY_SEPARATOR.'current'.DIRECTORY_SEPARATOR.'RELEASE_SHA';

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }

    /**
     * @return Closure(string, string ...): string  runs git in a directory
     */
    protected function gitOrSkip(): Closure
    {
        try {
            $found = (new Process(['git', '--version']))->run() === 0;
        } catch (\Throwable) {
            $found = false;
        }

        if (! $found) {
            $this->markTestSkipped('needs git on the PATH.');
        }

        return fn (string $dir, string ...$args) => (new Process(
            ['git', '-C', $dir, '-c', 'user.name=Slipstream', '-c', 'user.email=slipstream@example.com', ...$args]
        ))->mustRun()->getOutput();
    }
}
