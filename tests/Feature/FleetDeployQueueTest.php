<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Environment\EnvSync;
use Slipstream\Fleet\DeployQueue;
use Slipstream\Livewire\DeploymentsIndex;
use Slipstream\Livewire\ProjectChrome;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\PeerSignature;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

class FleetDeployQueueTest extends TestCase
{
    protected string $secret = 'fleet-key-secret';

    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        Slipstream::auth(fn () => true);
        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);
        app(SettingsRepository::class)->set('webhook_secret', $this->secret, encrypted: true);

        Server::query()->create([
            'server_name' => 'peer-1', 'base_url' => 'https://peer1.example',
            'enabled' => true, 'paused' => false,
        ]);
        Server::query()->create([
            'server_name' => 'peer-paused', 'enabled' => true, 'paused' => true,
        ]);
    }

    /** @return list<string> First artisan arg of every spawn call. */
    protected function spawned(): array
    {
        return array_map(fn ($c) => $c['args'][0], $this->detacher->calls);
    }

    public function test_deploy_now_runs_locally_and_queues_eligible_peers(): void
    {
        Livewire::test(DeploymentsIndex::class)->call('deployNow');

        $local = Deployment::query()->where('server_name', 'test-node')->first();
        $this->assertSame(Deployment::STATUS_PENDING, $local->status);
        $this->assertNotNull($local->fleetBatch());

        $peer = Deployment::query()->where('server_name', 'peer-1')->first();
        $this->assertSame(Deployment::STATUS_QUEUED, $peer->status);
        $this->assertSame($local->fleetBatch(), $peer->fleetBatch(), 'one batch spans the fleet');

        $this->assertNull(
            Deployment::query()->where('server_name', 'peer-paused')->first(),
            'paused servers are skipped, matching webhook semantics'
        );

        $this->assertContains('slipstream:deploy', $this->spawned());
        $this->assertContains('slipstream:deploy-nudge', $this->spawned());
    }

    public function test_rollback_is_fleet_wide_and_spawns_rollback_locally(): void
    {
        Livewire::test(DeploymentsIndex::class)->call('rollback');

        $this->assertSame(
            Deployment::TRIGGER_ROLLBACK,
            Deployment::query()->where('server_name', 'peer-1')->value('trigger')
        );
        $this->assertSame(
            Deployment::STATUS_QUEUED,
            Deployment::query()->where('server_name', 'peer-1')->value('status')
        );
        $this->assertContains('slipstream:rollback', $this->spawned());
        $this->assertContains('slipstream:deploy-nudge', $this->spawned());
    }

    public function test_redeploy_pins_the_same_sha_on_every_server(): void
    {
        $source = Deployment::query()->create([
            'server_name' => 'test-node', 'sha' => str_repeat('c', 40), 'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL, 'status' => Deployment::STATUS_DEPLOYED,
        ]);

        Livewire::test(DeploymentsIndex::class)->call('redeploy', $source->id);

        $peer = Deployment::query()
            ->where('server_name', 'peer-1')
            ->where('status', Deployment::STATUS_QUEUED)
            ->first();
        $this->assertSame($source->sha, $peer->sha, 'redeploy converges the fleet on one SHA');
    }

    public function test_deploy_modal_can_target_this_server_only(): void
    {
        Livewire::test(ProjectChrome::class)
            ->call('openDeployModal')
            ->set('deployScope', 'this')
            ->call('confirmDeploy');

        $this->assertSame(1, Deployment::query()->count());
        $this->assertSame('test-node', Deployment::query()->first()->server_name);
        $this->assertNotContains('slipstream:deploy-nudge', $this->spawned(), 'no peers queued, nothing to nudge');
    }

    public function test_queue_reports_explicitly_requested_ineligible_targets_as_skipped(): void
    {
        $result = app(DeployQueue::class)->queue(
            ['trigger' => Deployment::TRIGGER_MANUAL, 'branch' => 'main'],
            ['peer-paused']
        );

        $this->assertNull($result['local']);
        $this->assertSame([], $result['queued']);
        $this->assertSame(['peer-paused'], $result['skipped']);
        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_process_queued_claims_newest_and_supersedes_older(): void
    {
        $older = $this->queuedRow(['trigger' => Deployment::TRIGGER_MANUAL]);
        $newer = $this->queuedRow(['trigger' => Deployment::TRIGGER_ROLLBACK]);

        $claimed = app(DeployQueue::class)->processQueued();

        $this->assertSame($newer->id, $claimed->id);
        $this->assertSame(Deployment::STATUS_PENDING, $newer->fresh()->status);
        $this->assertSame(Deployment::STATUS_SUPERSEDED, $older->fresh()->status);
        $this->assertContains('slipstream:rollback', $this->spawned(), 'trigger picks the command');
    }

    public function test_process_queued_leaves_row_queued_while_paused(): void
    {
        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => true]);
        $row = $this->queuedRow();

        $this->assertNull(app(DeployQueue::class)->processQueued());
        $this->assertSame(Deployment::STATUS_QUEUED, $row->fresh()->status);
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_process_queued_reverts_the_claim_when_spawn_fails(): void
    {
        $row = $this->queuedRow();
        $this->detacher->throw = true;

        $this->assertNull(app(DeployQueue::class)->processQueued());
        $this->assertSame(
            Deployment::STATUS_QUEUED,
            $row->fresh()->status,
            'a failed spawn must put the row back for the next heartbeat'
        );
    }

    public function test_heartbeat_is_the_backstop_that_claims_queued_rows(): void
    {
        $row = $this->queuedRow();

        $this->artisan('slipstream:heartbeat')->assertSuccessful();

        $this->assertSame(Deployment::STATUS_PENDING, $row->fresh()->status);
        $this->assertContains('slipstream:deploy', $this->spawned());
    }

    public function test_valid_deploy_sync_nudge_claims_the_queued_row(): void
    {
        $row = $this->queuedRow();

        $this->postDeploySync($this->body(), time())->assertOk();

        $this->assertSame(Deployment::STATUS_PENDING, $row->fresh()->status);
        $this->assertContains('slipstream:deploy', $this->spawned());
    }

    public function test_env_sync_signature_is_not_valid_on_the_deploy_sync_endpoint(): void
    {
        $this->queuedRow();
        $body = $this->body();
        $ts = (string) time();

        // Domain separation: a valid ENV-SYNC signature must not verify here.
        $envSig = PeerSignature::sign(EnvSync::SIGNING_VERSION, $ts, $body, $this->secret);

        $this->postDeploySyncRaw($body, $envSig, $ts)->assertStatus(401);
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $this->queuedRow();

        $this->postDeploySync($this->body(), time() - 4000)->assertStatus(401);
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_plain_body_hmac_is_rejected(): void
    {
        $this->queuedRow();
        $body = $this->body();
        $forged = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        $this->postDeploySyncRaw($body, $forged, (string) time())->assertStatus(401);
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_deploy_nudge_posts_signed_requests_only_to_peers_with_base_url(): void
    {
        Http::fake();

        // A peer without a base_url must not be nudged (heartbeat-only).
        Server::query()->create(['server_name' => 'peer-nobase', 'enabled' => true]);
        $this->queuedRow(['server_name' => 'peer-1']);
        $this->queuedRow(['server_name' => 'peer-nobase']);

        $this->artisan('slipstream:deploy-nudge', ['--batch' => 'batch-1'])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://peer1.example')
                && str_contains($request->url(), '/hooks/deploy-sync')
                && $request->hasHeader('X-Slipstream-Signature')
                && $request->hasHeader('X-Slipstream-Timestamp');
        });
        Http::assertSentCount(1);
    }

    public function test_prune_expires_queued_rows_for_servers_that_never_claimed(): void
    {
        $stale = $this->queuedRow();
        $stale->forceFill(['created_at' => now()->subHours(30)])->save();
        $fresh = $this->queuedRow();

        $this->artisan('slipstream:prune')->assertSuccessful();

        $this->assertSame(Deployment::STATUS_FAILED, $stale->fresh()->status);
        $this->assertStringContainsString('Expired', $stale->fresh()->failure_reason);
        $this->assertSame(Deployment::STATUS_QUEUED, $fresh->fresh()->status);
    }

    /**
     * A queued deployment row for this node, as a fleet action would create it.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function queuedRow(array $overrides = []): Deployment
    {
        return Deployment::query()->create(array_merge([
            'server_name' => 'test-node',
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_QUEUED,
            'meta' => ['fleet_batch' => 'batch-1', 'queued_from' => 'peer-1'],
        ], $overrides));
    }

    protected function body(): string
    {
        return json_encode(['batch' => 'batch-1', 'from_server' => 'peer-1'], JSON_THROW_ON_ERROR);
    }

    protected function postDeploySync(string $body, int $timestamp)
    {
        $sig = PeerSignature::sign(DeployQueue::SIGNING_VERSION, (string) $timestamp, $body, $this->secret);

        return $this->postDeploySyncRaw($body, $sig, (string) $timestamp);
    }

    protected function postDeploySyncRaw(string $body, string $signature, string $timestamp)
    {
        $server = $this->transformHeadersToServerVars([
            'X-Slipstream-Deploy-Sync' => '1',
            'X-Slipstream-Signature' => $signature,
            'X-Slipstream-Timestamp' => $timestamp,
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', '/slipstream/hooks/deploy-sync', [], [], [], $server, $body);
    }
}
