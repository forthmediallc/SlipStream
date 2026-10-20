<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\ArtifactFixture;
use Slipstream\Tests\TestCase;

class StatusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false, 'current_sha' => str_repeat('a', 40), 'last_heartbeat_at' => now()]);
        Server::query()->create(['server_name' => 'peer-1', 'enabled' => true, 'paused' => true, 'current_sha' => str_repeat('b', 40), 'last_heartbeat_at' => now()]);
        Server::query()->create(['server_name' => 'peer-2', 'enabled' => true, 'paused' => false, 'current_sha' => str_repeat('a', 40), 'last_heartbeat_at' => now()->subMinutes(10)]);

        Deployment::query()->create(['server_name' => 'test-node', 'trigger' => 'webhook', 'status' => Deployment::STATUS_DEPLOYED, 'sha' => str_repeat('a', 40), 'branch' => 'main', 'finished_at' => now()->subHour(), 'duration_ms' => 42_000]);
        Deployment::query()->create(['server_name' => 'peer-1', 'trigger' => 'manual', 'status' => Deployment::STATUS_BUILDING, 'branch' => 'main', 'started_at' => now()]);
        Deployment::query()->create(['server_name' => 'test-node', 'trigger' => 'manual', 'status' => Deployment::STATUS_FAILED, 'branch' => 'main', 'failure_reason' => 'composer exploded', 'finished_at' => now()->subMinutes(30)]);

        Pipeline::query()->create(['name' => 'main', 'branch_pattern' => 'main', 'auto_deploy' => true, 'enabled' => true, 'server_names' => null]);
        app(SettingsRepository::class)->set('repo_url', 'git@github.com:org/app.git');
    }

    public function test_artifact_mode_shows_the_source_and_the_pointer(): void
    {
        $fixture = (new ArtifactFixture)->configure()->writeCheckout()->package();
        $fixture->publish('ci');

        try {
            $this->artisan('slipstream:status')
                ->expectsOutputToContain('artifact · disk artifacts · prefix app · environment production')
                ->expectsOutputToContain('aaaaaaa · sequence')
                ->assertExitCode(0);

            $this->artisan('slipstream:status --json')
                ->expectsOutputToContain('"source": "artifact"')
                ->assertExitCode(0);
        } finally {
            $fixture->remove();
        }
    }

    /**
     * The back-off status shows is the poller's: in artifact mode it belongs
     * to the build the pointer names, not to another build of that commit.
     */
    public function test_artifact_mode_shows_the_back_off_of_the_build_the_pointer_names(): void
    {
        $fixture = (new ArtifactFixture)->configure()->writeCheckout()->package();
        $fixture->publish('ci');
        Server::query()->where('server_name', 'test-node')->update(['poll_seen_sha' => $fixture->sha]);

        try {
            Deployment::query()->create([
                'server_name' => 'test-node', 'trigger' => Deployment::TRIGGER_POLL, 'status' => Deployment::STATUS_FAILED, 'sha' => $fixture->sha,
                'branch' => 'production', 'finished_at' => now(), 'meta' => ['pointer' => ['sequence' => 1, 'manifest_sha256' => str_repeat('0', 64)]],
            ]);
            $this->artisan('slipstream:status --json')
                ->expectsOutputToContain('"backoff": null')
                ->assertExitCode(0);

            Deployment::query()->create([
                'server_name' => 'test-node', 'trigger' => Deployment::TRIGGER_POLL, 'status' => Deployment::STATUS_FAILED, 'sha' => $fixture->sha,
                'branch' => 'production', 'finished_at' => now(), 'meta' => ['pointer' => ['sequence' => $fixture->pointer->sequence, 'manifest_sha256' => $fixture->pointer->manifestSha256]],
            ]);
            $this->artisan('slipstream:status --json')
                ->expectsOutputToContain('"failures": 1')
                ->assertExitCode(0);
        } finally {
            $fixture->remove();
        }
    }

    public function test_the_overview_reads_like_the_front_page(): void
    {
        // One substring per output line, in output order (see the memory note
        // on console expectations).
        $this->artisan('slipstream:status')
            ->expectsOutputToContain('git@github.com:org/app.git @ main')
            ->expectsOutputToContain('test-node (this)')
            ->expectsOutputToContain('Offline: peer-2')
            ->expectsOutputToContain('building')
            ->expectsOutputToContain('Failed in the last 24h: 1')
            ->expectsOutputToContain('composer exploded')
            ->assertExitCode(0);
    }

    public function test_json_carries_the_same_picture(): void
    {
        // The JSON document is one console write, so one substring per run.
        $this->artisan('slipstream:status --json')->expectsOutputToContain('"pci_mode": false')->assertExitCode(0);
        $this->artisan('slipstream:status --json')->expectsOutputToContain('"has_drift": true')->assertExitCode(0);
        $this->artisan('slipstream:status --json')->expectsOutputToContain('"offline": [')->assertExitCode(0);
    }

    public function test_an_empty_install_does_not_crash(): void
    {
        Server::query()->delete();
        Deployment::query()->delete();
        Pipeline::query()->delete();

        $this->artisan('slipstream:status')
            ->expectsOutputToContain('none registered yet')
            ->expectsOutputToContain('nothing queued, pending or building')
            ->assertExitCode(0);
    }
}
