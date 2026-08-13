<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\StuckDeploymentsSweeper;
use Slipstream\Models\Deployment;
use Slipstream\Tests\TestCase;

class StuckDeploymentsSweeperTest extends TestCase
{
    public function test_it_fails_building_pending_and_queued_rows_past_their_windows(): void
    {
        $building = $this->deployment(Deployment::STATUS_BUILDING, ['started_at' => now()->subHours(5)]);
        $pending = $this->deployment(Deployment::STATUS_PENDING);
        $pending->forceFill(['updated_at' => now()->subHours(5)])->saveQuietly();
        $queued = $this->deployment(Deployment::STATUS_QUEUED);
        $queued->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $counts = app(StuckDeploymentsSweeper::class)->sweep();

        $this->assertSame(['building' => 1, 'pending' => 1, 'queued' => 1], $counts);
        $this->assertSame(Deployment::STATUS_FAILED, $building->fresh()->status);
        $this->assertSame(Deployment::STATUS_FAILED, $pending->fresh()->status);
        $this->assertSame(Deployment::STATUS_FAILED, $queued->fresh()->status);
        $this->assertNotNull($building->fresh()->finished_at);
    }

    public function test_it_leaves_in_flight_and_terminal_rows_untouched(): void
    {
        $running = $this->deployment(Deployment::STATUS_BUILDING, ['started_at' => now()->subMinutes(30)]);
        $deployed = $this->deployment(Deployment::STATUS_DEPLOYED, ['started_at' => now()->subDays(3)]);

        $counts = app(StuckDeploymentsSweeper::class)->sweep();

        $this->assertSame(['building' => 0, 'pending' => 0, 'queued' => 0], $counts);
        $this->assertSame(Deployment::STATUS_BUILDING, $running->fresh()->status);
        $this->assertSame(Deployment::STATUS_DEPLOYED, $deployed->fresh()->status);
    }

    public function test_stuck_minutes_is_configurable(): void
    {
        config()->set('slipstream.deploy.stuck_minutes', 10);
        $building = $this->deployment(Deployment::STATUS_BUILDING, ['started_at' => now()->subMinutes(15)]);

        app(StuckDeploymentsSweeper::class)->sweep();

        $this->assertSame(Deployment::STATUS_FAILED, $building->fresh()->status);
        $this->assertStringContainsString('over 10 minutes', $building->fresh()->failure_reason);
    }

    public function test_queued_expiry_can_be_disabled(): void
    {
        config()->set('slipstream.deploy.queued_max_hours', 0);
        $queued = $this->deployment(Deployment::STATUS_QUEUED);
        $queued->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

        $counts = app(StuckDeploymentsSweeper::class)->sweep();

        $this->assertSame(0, $counts['queued']);
        $this->assertSame(Deployment::STATUS_QUEUED, $queued->fresh()->status);
    }

    /**
     * 0 = disabled, matching every neighbouring retention key. Without the
     * guard a zero threshold matches every in-flight row the moment it starts
     * — and the sweep now runs every minute, not once a day.
     */
    public function test_zero_stuck_minutes_disables_the_watchdog(): void
    {
        config()->set('slipstream.deploy.stuck_minutes', 0);
        $building = $this->deployment(Deployment::STATUS_BUILDING, ['started_at' => now()->subDays(3)]);

        $counts = app(StuckDeploymentsSweeper::class)->sweep();

        $this->assertSame(0, $counts['building']);
        $this->assertSame(Deployment::STATUS_BUILDING, $building->fresh()->status);
    }

    /**
     * The heartbeat sweeps scoped to its own server: one node's stuck_minutes
     * must never judge a deploy another node is still running.
     */
    public function test_a_scoped_sweep_leaves_other_servers_rows_alone(): void
    {
        $mine = $this->deployment(Deployment::STATUS_BUILDING, ['started_at' => now()->subHours(5)]);
        $theirs = $this->deployment(Deployment::STATUS_BUILDING, [
            'server_name' => 'other-node',
            'started_at' => now()->subHours(5),
        ]);

        $counts = app(StuckDeploymentsSweeper::class)->sweep('test-node');

        $this->assertSame(1, $counts['building']);
        $this->assertSame(Deployment::STATUS_FAILED, $mine->fresh()->status);
        $this->assertSame(Deployment::STATUS_BUILDING, $theirs->fresh()->status, "another node's deploy is not ours to judge");

        // The unscoped daily sweep remains the backstop for dead nodes.
        $this->assertSame(1, app(StuckDeploymentsSweeper::class)->sweep()['building']);
        $this->assertSame(Deployment::STATUS_FAILED, $theirs->fresh()->status);
    }

    protected function deployment(string $status, array $attributes = []): Deployment
    {
        return Deployment::query()->create(array_merge([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'status' => $status,
        ], $attributes));
    }
}
