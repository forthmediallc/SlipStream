<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\ActivityLog;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

/**
 * The dashboard's fleet-wide Deploy / Redeploy / Rollback as --fleet on the
 * deploy and rollback commands, and the fleet key from the secret command.
 */
class FleetCliTest extends TestCase
{
    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);
        app(SettingsRepository::class)->set('webhook_secret', 'secret', encrypted: true);

        Server::query()->create(['server_name' => 'peer-1', 'base_url' => 'https://peer1.example', 'enabled' => true, 'paused' => false]);
        Server::query()->create(['server_name' => 'peer-paused', 'enabled' => true, 'paused' => true]);
    }

    /** @return list<string> */
    protected function spawned(): array
    {
        return array_map(fn ($c) => $c['args'][0], $this->detacher->calls);
    }

    public function test_deploy_fleet_runs_here_detached_and_queues_eligible_peers(): void
    {
        $this->artisan('slipstream:deploy --fleet')
            ->expectsOutputToContain('started (detached)')
            ->expectsOutputToContain('peer-1')
            ->assertExitCode(0);

        $local = Deployment::query()->where('server_name', 'test-node')->sole();
        $this->assertSame(Deployment::STATUS_PENDING, $local->status);
        $this->assertSame('main', $local->branch);
        $this->assertSame(Deployment::TRIGGER_MANUAL, $local->trigger);

        $peer = Deployment::query()->where('server_name', 'peer-1')->sole();
        $this->assertSame(Deployment::STATUS_QUEUED, $peer->status);
        $this->assertSame($local->fleetBatch(), $peer->fleetBatch());
        $this->assertNull(Deployment::query()->where('server_name', 'peer-paused')->first());

        $this->assertContains('slipstream:deploy', $this->spawned());
        $this->assertContains('slipstream:deploy-nudge', $this->spawned());
        $this->assertTrue(ActivityLog::query()->where('action', 'deploy.started')->exists());
    }

    public function test_tag_sha_and_skipped_hooks_are_recorded_like_the_deploy_modal(): void
    {
        $this->artisan('slipstream:deploy --fleet --tag=v1.2.3 --skip-hooks=3,4')->assertExitCode(0);

        $local = Deployment::query()->where('server_name', 'test-node')->sole();
        $this->assertSame('v1.2.3', $local->branch);
        $this->assertSame('tag', $local->deployFrom());
        $this->assertSame([3, 4], $local->skippedHookIds());

        Deployment::query()->delete();
        $this->artisan('slipstream:deploy --fleet --sha='.str_repeat('c', 40))->assertExitCode(0);
        $this->assertSame(str_repeat('c', 40), Deployment::query()->where('server_name', 'peer-1')->value('sha'), 'a pinned SHA converges the fleet');
    }

    public function test_conflicting_or_malformed_refs_are_refused_before_anything_is_created(): void
    {
        $this->artisan('slipstream:deploy --fleet --tag=v1 --branch=main')
            ->expectsOutputToContain('exclusive')
            ->assertExitCode(1);

        $this->artisan('slipstream:deploy --fleet --branch=-oops')
            ->expectsOutputToContain('not a valid git ref')
            ->assertExitCode(1);

        $this->artisan('slipstream:deploy --fleet --sha=nothex')
            ->expectsOutputToContain('hex object id')
            ->assertExitCode(1);

        $this->assertSame(0, Deployment::query()->count());
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_a_pci_node_refuses_to_drive_peers(): void
    {
        config()->set('slipstream.pci.enabled', true);

        $this->artisan('slipstream:deploy --fleet')
            ->expectsOutputToContain('does not drive peers')
            ->assertExitCode(1);

        $this->artisan('slipstream:rollback --fleet')
            ->expectsOutputToContain('does not drive peers')
            ->assertExitCode(1);

        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_rollback_fleet_queues_a_rollback_everywhere(): void
    {
        $this->artisan('slipstream:rollback --fleet')
            ->expectsOutputToContain('rollback started (detached)')
            ->assertExitCode(0);

        $this->assertSame(Deployment::TRIGGER_ROLLBACK, Deployment::query()->where('server_name', 'test-node')->value('trigger'));
        $this->assertSame(Deployment::STATUS_QUEUED, Deployment::query()->where('server_name', 'peer-1')->value('status'));
        $this->assertContains('slipstream:rollback', $this->spawned());
        $this->assertTrue(ActivityLog::query()->where('action', 'rollback.started')->exists());
    }

    public function test_the_fleet_key_can_be_separated_and_rotated_from_the_shell(): void
    {
        $settings = app(SettingsRepository::class);
        $this->assertFalse($settings->hasSeparatePeerSecret());

        $this->artisan('slipstream:webhook-secret --rotate-fleet-key')
            ->expectsOutputToContain('separated from the webhook secret')
            ->assertExitCode(0);
        $this->assertTrue($settings->hasSeparatePeerSecret());
        $first = $settings->peerSecret();

        $this->artisan('slipstream:webhook-secret --rotate-fleet-key')
            ->expectsOutputToContain('Fleet key rotated')
            ->doesntExpectOutputToContain($first)
            ->assertExitCode(0);
        $this->assertNotSame($first, $settings->peerSecret());
        $this->assertSame('secret', $settings->webhookSecret(), 'the webhook secret is untouched');
    }
}
