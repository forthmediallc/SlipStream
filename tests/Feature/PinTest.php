<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Fleet\NodeHold;
use Slipstream\Fleet\Poller;
use Slipstream\Models\ActivityLog;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Release\ReleaseSource;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\Support\FakeReleaseSource;
use Slipstream\Tests\TestCase;

/**
 * In poll mode a node that runs something other than what the ref names
 * would be "corrected" by the next check. A pin holds it there until someone
 * resumes it.
 */
class PinTest extends TestCase
{
    protected const OLD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected const REF = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected string $root;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('slipstream.trigger.mode', 'poll');
        $app['config']->set('slipstream.trigger.ref', 'refs/heads/production');
        $app['config']->set('slipstream.trigger.poll_jitter', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-pin-'.bin2hex(random_bytes(4));
        mkdir($this->root.DIRECTORY_SEPARATOR.'current', 0777, true);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'current'.DIRECTORY_SEPARATOR.'RELEASE_SHA', self::OLD."\n");
        config()->set('slipstream.app_root', $this->root);

        $this->app->instance(ReleaseSource::class, (new FakeReleaseSource)->at(self::REF));
        $this->app->instance(ProcessDetacher::class, new FakeProcessDetacher);

        Server::query()->create([
            'server_name' => 'test-node', 'enabled' => true, 'paused' => false, 'poll_seen_sha' => self::REF,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_a_rollback_pins_the_node_and_the_poller_leaves_it_there(): void
    {
        $this->finished(Deployment::STATUS_ROLLED_BACK, Deployment::TRIGGER_ROLLBACK, self::OLD);

        $server = Server::query()->sole();
        $this->assertTrue($server->isPinned());
        $this->assertSame([self::OLD, 'rollback'], [$server->pinned_sha, $server->pinned_reason]);

        $tick = app(Poller::class)->tick(force: true);
        $this->assertSame(Poller::HELD, $tick['result']);
        $this->assertStringContainsString('pinned at aaaaaaa (rollback)', $tick['detail']);
        $this->assertSame(1, Deployment::query()->count(), 'no redeploy of the commit rolled back from');
        $this->assertTrue(ActivityLog::query()->where('action', 'node.pinned')->exists());
    }

    public function test_a_manual_deploy_off_the_ref_pins_and_one_of_the_ref_does_not(): void
    {
        $this->finished(Deployment::STATUS_DEPLOYED, Deployment::TRIGGER_MANUAL, self::OLD);
        $this->assertSame('manual deploy', Server::query()->sole()->pinned_reason);

        app(NodeHold::class)->resume(Server::query()->sole());

        // Forcing the ref's own commit (a retry after back-off) is no detour.
        $this->finished(Deployment::STATUS_DEPLOYED, Deployment::TRIGGER_MANUAL, self::REF);
        $this->assertFalse(Server::query()->sole()->isPinned());

        $this->finished(Deployment::STATUS_DEPLOYED, Deployment::TRIGGER_MANUAL, self::OLD, ['poll_ref' => 'branch:production']);
        $this->assertFalse(Server::query()->sole()->isPinned(), 'deployed as the ref resolved then');
    }

    public function test_the_pollers_and_bootstraps_own_deploys_never_pin(): void
    {
        $this->finished(Deployment::STATUS_DEPLOYED, Deployment::TRIGGER_POLL, self::OLD);
        $this->finished(Deployment::STATUS_DEPLOYED, Deployment::TRIGGER_BOOTSTRAP, self::OLD);

        $this->assertFalse(Server::query()->sole()->isPinned());
    }

    public function test_webhook_mode_never_pins(): void
    {
        config()->set('slipstream.trigger.mode', 'webhook');

        $this->finished(Deployment::STATUS_ROLLED_BACK, Deployment::TRIGGER_ROLLBACK, self::OLD);

        $this->assertFalse(Server::query()->sole()->paused);
    }

    public function test_resuming_lifts_the_pin_and_the_next_check_converges(): void
    {
        app(NodeHold::class)->pin(self::OLD, 'rollback');

        $this->artisan('slipstream:servers resume')
            ->expectsOutputToContain('unpinned and resumed')
            ->assertExitCode(0);

        $server = Server::query()->sole();
        $this->assertFalse($server->paused);
        $this->assertNull($server->pinned_sha);
        $this->assertSame(Poller::DEPLOYING, app(Poller::class)->tick(force: true)['result']);
        $this->assertTrue(ActivityLog::query()->where('action', 'node.resumed')->exists());
    }

    public function test_status_and_the_server_list_show_the_pin(): void
    {
        app(NodeHold::class)->pin(self::OLD, 'rollback');

        $this->artisan('slipstream:status')
            ->expectsOutputToContain('Held: pinned at aaaaaaa (rollback)')
            ->assertExitCode(0);

        $this->artisan('slipstream:servers')
            ->expectsOutputToContain('pinned aaaaaaa')
            ->assertExitCode(0);
    }

    /**
     * The real command on a real release layout (symlinks, so POSIX only):
     * slipstream:rollback in poll mode leaves the node pinned.
     */
    public function test_the_rollback_command_pins_the_node(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs a symlinked release layout.');
        }

        $this->removeTree($this->root.DIRECTORY_SEPARATOR.'current');
        $releases = $this->root.DIRECTORY_SEPARATOR.'releases';
        foreach (['20261001_000000_aaaaaaa' => self::OLD, '20261002_000000_bbbbbbb' => self::REF] as $name => $sha) {
            mkdir($releases.DIRECTORY_SEPARATOR.$name, 0777, true);
            file_put_contents($releases.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.'RELEASE_SHA', $sha."\n");
        }
        touch($releases.DIRECTORY_SEPARATOR.'20261001_000000_aaaaaaa', time() - 3600);
        if (! @symlink($releases.DIRECTORY_SEPARATOR.'20261002_000000_bbbbbbb', $this->root.DIRECTORY_SEPARATOR.'current')) {
            $this->markTestSkipped('creating symlinks is not permitted here.');
        }
        config()->set('slipstream.services.restart_fpm', false);

        $this->artisan('slipstream:rollback')->assertExitCode(0);

        $server = Server::query()->sole();
        $this->assertTrue($server->isPinned());
        $this->assertSame([self::OLD, 'rollback'], [$server->pinned_sha, $server->pinned_reason]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function finished(string $status, string $trigger, string $sha, array $meta = []): void
    {
        $deployment = Deployment::query()->create([
            'server_name' => 'test-node', 'sha' => $sha, 'branch' => 'production',
            'trigger' => $trigger, 'status' => $status, 'finished_at' => now(), 'meta' => $meta ?: null,
        ]);

        app(NodeHold::class)->afterDeploy($deployment);
    }
}
