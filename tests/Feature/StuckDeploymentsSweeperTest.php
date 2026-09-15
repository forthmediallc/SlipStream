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
        $pending = $this->deployment(Deployment::STATUS_PENDING);
        $pending->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();

        $counts = app(StuckDeploymentsSweeper::class)->sweep();

        $this->assertSame(0, $counts['building']);
        $this->assertSame(0, $counts['pending'], 'pending shares the master switch');
        $this->assertSame(Deployment::STATUS_BUILDING, $building->fresh()->status);
        $this->assertSame(Deployment::STATUS_PENDING, $pending->fresh()->status);
    }

    /**
     * "pending" only covers process start-up, so a spawn that never ran
     * artisan (the FPM daemon as interpreter, a wrong SLIPSTREAM_PHP) is
     * judged on its own short window, not the two hours a real build may
     * legitimately take — and the reason says where to look.
     */
    public function test_a_pending_row_is_failed_on_its_own_short_window(): void
    {
        $pending = $this->deployment(Deployment::STATUS_PENDING);
        $pending->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();
        $building = $this->deployment(Deployment::STATUS_BUILDING, ['started_at' => now()->subMinutes(10)]);

        $counts = app(StuckDeploymentsSweeper::class)->sweep();

        $this->assertSame(['building' => 0, 'pending' => 1, 'queued' => 0], $counts);
        $this->assertSame(Deployment::STATUS_FAILED, $pending->fresh()->status);
        $this->assertStringContainsString('after 5 minutes', $pending->fresh()->failure_reason);
        $this->assertStringContainsString('never started', $pending->fresh()->failure_reason);
        $this->assertStringContainsString('slipstream:doctor', $pending->fresh()->failure_reason);
        $this->assertSame(Deployment::STATUS_BUILDING, $building->fresh()->status);
    }

    public function test_a_pending_row_still_starting_up_is_left_alone(): void
    {
        $pending = $this->deployment(Deployment::STATUS_PENDING);
        $pending->forceFill(['updated_at' => now()->subMinutes(2)])->saveQuietly();

        $this->assertSame(0, app(StuckDeploymentsSweeper::class)->sweep()['pending']);
        $this->assertSame(Deployment::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_the_pending_window_never_exceeds_stuck_minutes(): void
    {
        config()->set('slipstream.deploy.stuck_minutes', 3);
        config()->set('slipstream.deploy.pending_stuck_minutes', 60);
        $pending = $this->deployment(Deployment::STATUS_PENDING);
        $pending->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();

        app(StuckDeploymentsSweeper::class)->sweep();

        $this->assertSame(Deployment::STATUS_FAILED, $pending->fresh()->status);
        $this->assertStringContainsString('after 3 minutes', $pending->fresh()->failure_reason);
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
