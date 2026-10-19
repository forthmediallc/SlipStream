<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Fleet\Poller;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Release\ReleaseSource;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\Support\FakeReleaseSource;
use Slipstream\Tests\TestCase;

/**
 * Poll mode: each node looks at the deploy ref and converges on it by
 * itself, with nothing inbound.
 */
class PollModeTest extends TestCase
{
    protected const OLD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected const NEW = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected const NEWER = 'cccccccccccccccccccccccccccccccccccccccc';

    protected FakeReleaseSource $source;

    protected FakeProcessDetacher $detacher;

    protected string $root;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Routes are registered while the provider boots: set before setUp().
        $app['config']->set('slipstream.trigger.mode', 'poll');
        $app['config']->set('slipstream.trigger.ref', 'refs/heads/production');
        $app['config']->set('slipstream.trigger.poll_jitter', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-poll-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);

        $this->source = (new FakeReleaseSource)->at(self::NEW);
        $this->app->instance(ReleaseSource::class, $this->source);

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);

        $this->running(self::OLD);
        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_a_new_commit_on_the_ref_deploys_and_the_same_commit_is_a_no_op(): void
    {
        $tick = $this->tick();

        $this->assertSame(Poller::DEPLOYING, $tick['result']);
        $deployment = Deployment::query()->sole();
        $this->assertSame(Deployment::TRIGGER_POLL, $deployment->trigger);
        $this->assertSame(self::NEW, $deployment->sha);
        $this->assertSame('production', $deployment->branch);
        $this->assertSame('branch:production', $deployment->meta['poll_ref']);
        $this->assertSame([['args' => ['slipstream:deploy', '--deployment='.$deployment->id], 'cwd' => null]], $this->detacher->calls);

        // The deploy lands; the next check finds the node converged.
        $deployment->update(['status' => Deployment::STATUS_DEPLOYED, 'finished_at' => now()]);
        $this->running(self::NEW);

        $this->assertSame(Poller::UP_TO_DATE, $this->tick()['result']);
        $this->assertSame(1, Deployment::query()->count());
        $this->assertCount(1, $this->detacher->calls);
    }

    public function test_a_failed_commit_is_not_retried_every_minute(): void
    {
        $this->freezeTime();
        $this->failed(self::NEW);

        $this->assertSame(Poller::BACKOFF, $this->tick()['result']);
        $this->travel(4)->minutes();
        $this->assertSame(Poller::BACKOFF, $this->tick()['result'], 'still inside the first five minutes');
        $this->assertSame(1, Deployment::query()->count());

        $this->travel(2)->minutes();
        $this->assertSame(Poller::DEPLOYING, $this->tick()['result'], 'retried once the back-off ran out');

        // A second failure doubles the wait.
        Deployment::query()->latest('id')->first()->update(['status' => Deployment::STATUS_FAILED, 'finished_at' => now()]);
        $this->travel(6)->minutes();
        $this->assertSame(Poller::BACKOFF, $this->tick()['result']);
        $this->assertStringContainsString('failed 2×', (string) Server::query()->sole()->poll_detail);
        $this->travel(5)->minutes();
        $this->assertSame(Poller::DEPLOYING, $this->tick()['result']);
    }

    public function test_a_newer_commit_is_tried_at_once_despite_a_failed_one(): void
    {
        $this->failed(self::NEW);
        $this->source->at(self::NEWER);

        $this->assertSame(Poller::DEPLOYING, $this->tick()['result']);
        $this->assertSame(self::NEWER, Deployment::query()->latest('id')->value('sha'));
    }

    public function test_pause_pin_disable_and_the_fleet_wide_pause_all_hold_the_node(): void
    {
        $server = Server::query()->sole();

        foreach ([
            'paused' => fn () => $server->update(['paused' => true]),
            'pinned at' => fn () => $server->update(['paused' => true, 'pinned_sha' => self::OLD, 'pinned_reason' => 'rollback', 'pinned_at' => now()]),
            'disabled' => fn () => $server->update(['paused' => false, 'pinned_sha' => null, 'enabled' => false]),
            'SLIPSTREAM_PAUSED' => function () use ($server) {
                $server->update(['enabled' => true]);
                config()->set('slipstream.node.paused', true);
            },
        ] as $expected => $hold) {
            $hold();
            $tick = $this->tick();

            $this->assertSame(Poller::HELD, $tick['result'], $expected);
            $this->assertStringContainsString($expected, $tick['detail']);
        }

        $this->assertSame(0, Deployment::query()->count());
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_nothing_starts_while_a_deploy_is_in_flight(): void
    {
        Deployment::query()->create(['server_name' => 'test-node', 'trigger' => 'manual', 'status' => Deployment::STATUS_BUILDING]);

        $this->assertSame(Poller::BUSY, $this->tick()['result']);
        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_checks_follow_the_interval(): void
    {
        $this->freezeTime();
        $poller = app(Poller::class);

        $this->assertNotNull($poller->tick(), 'the first check is due');
        Deployment::query()->update(['status' => Deployment::STATUS_DEPLOYED]);
        $this->running(self::NEW);

        $this->assertNull($poller->tick(), 'the next one is a minute away');
        $this->travel(61)->seconds();
        $this->assertSame(Poller::UP_TO_DATE, $poller->tick()['result']);
    }

    public function test_a_source_error_is_recorded_not_thrown(): void
    {
        $this->source->failing('Cannot read branch production from origin: Permission denied (publickey).');

        $tick = $this->tick();

        $this->assertSame(Poller::ERROR, $tick['result']);
        $this->assertStringContainsString('Permission denied', (string) Server::query()->sole()->poll_detail);
        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_the_minute_heartbeat_runs_the_check(): void
    {
        $this->artisan('slipstream:heartbeat')
            ->expectsOutputToContain('deploying')
            ->assertExitCode(0);

        $this->assertSame(self::NEW, Deployment::query()->sole()->sha);
    }

    public function test_there_is_no_webhook_or_relay_route_to_reach(): void
    {
        $this->postJson('/slipstream/hooks/deploy', [])->assertNotFound();
        $this->postJson('/slipstream/hooks/relay', [])->assertNotFound();

        // Normal mode keeps its peer endpoints (and dashboard): only the
        // inbound deploy triggers are gone.
        $this->postJson('/slipstream/hooks/env-sync', [])->assertStatus(401);
    }

    public function test_status_shows_the_trigger_and_the_last_check(): void
    {
        $this->tick();

        $this->artisan('slipstream:status')
            ->expectsOutputToContain('poll · branch production')
            ->expectsOutputToContain('aaaaaaa')
            ->expectsOutputToContain('deploying')
            ->expectsOutputToContain('production at bbbbbbb')
            ->assertExitCode(0);

        $this->artisan('slipstream:status --json')
            ->expectsOutputToContain('"mode": "poll"')
            ->assertExitCode(0);
    }

    public function test_status_says_when_a_commit_is_backing_off(): void
    {
        $this->failed(self::NEW);
        $this->tick();

        $this->artisan('slipstream:status')
            ->expectsOutputToContain('slipstream:deploy --sha='.self::NEW)
            ->assertExitCode(0);
    }

    public function test_doctor_checks_the_ref_and_the_absent_webhook(): void
    {
        Http::fake();

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('poll · branch production → production at bbbbbbb')
            ->expectsOutputToContain('not registered (poll mode')
            ->assertExitCode(1); // the bare layout fails elsewhere

        Http::assertNothingSent();
    }

    public function test_doctor_fails_a_ref_the_deploy_key_cannot_read(): void
    {
        Http::fake();
        $this->source->failing('Cannot read branch production from origin: Permission denied (publickey).');

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('poll mode cannot read the deploy ref')
            ->assertExitCode(1);
    }

    public function test_a_cli_deploy_with_nothing_named_deploys_the_ref(): void
    {
        // The deploy itself fails on this bare layout; what matters is what
        // it was asked to deploy.
        $this->artisan('slipstream:deploy')->assertExitCode(1);

        $deployment = Deployment::query()->sole();
        $this->assertSame(self::NEW, $deployment->sha);
        $this->assertSame('production', $deployment->branch);
        $this->assertSame('branch:production', $deployment->meta['poll_ref']);
    }

    /**
     * @return array{result: string, detail: string, deployment: ?Deployment}
     */
    protected function tick(): array
    {
        return app(Poller::class)->tick(force: true);
    }

    protected function running(string $sha): void
    {
        $current = $this->root.DIRECTORY_SEPARATOR.'current';
        if (! is_dir($current)) {
            mkdir($current, 0777, true);
        }
        file_put_contents($current.DIRECTORY_SEPARATOR.'RELEASE_SHA', $sha."\n");
    }

    protected function failed(string $sha): Deployment
    {
        return Deployment::query()->create([
            'server_name' => 'test-node',
            'sha' => $sha,
            'branch' => 'production',
            'trigger' => Deployment::TRIGGER_POLL,
            'status' => Deployment::STATUS_FAILED,
            'failure_reason' => 'health check failed',
            'finished_at' => now(),
        ]);
    }
}
