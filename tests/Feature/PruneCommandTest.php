<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentEvent;
use Slipstream\Models\EnvPush;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Tests\TestCase;

class PruneCommandTest extends TestCase
{
    public function test_prune_removes_old_events_and_deliveries_and_fails_stuck_deploys(): void
    {
        $deployment = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_DEPLOYED,
        ]);

        DeploymentEvent::query()->create([
            'deployment_id' => $deployment->id, 'sequence' => 1, 'type' => 'log',
            'level' => 'info', 'message' => 'old', 'created_at' => now()->subDays(40),
        ]);
        DeploymentEvent::query()->create([
            'deployment_id' => $deployment->id, 'sequence' => 2, 'type' => 'log',
            'level' => 'info', 'message' => 'recent', 'created_at' => now()->subDay(),
        ]);

        WebhookDelivery::query()->forceCreate([
            'provider' => 'github', 'server_name' => 'test-node', 'signature_valid' => true,
            'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40),
        ]);
        WebhookDelivery::query()->forceCreate([
            'provider' => 'github', 'server_name' => 'test-node', 'signature_valid' => true,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $stuck = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'status' => Deployment::STATUS_BUILDING,
            'started_at' => now()->subHours(5),
        ]);

        $this->artisan('slipstream:prune')->assertExitCode(0);

        $this->assertSame(1, DeploymentEvent::query()->count(), 'old event should be pruned');
        $this->assertSame('recent', DeploymentEvent::query()->first()->message);
        $this->assertSame(1, WebhookDelivery::query()->count(), 'old delivery should be pruned');

        $this->assertSame(Deployment::STATUS_FAILED, $stuck->fresh()->status, 'stuck deploy should be failed by watchdog');
    }

    public function test_prune_trims_terminal_expires_pending_and_fails_stuck_env_pushes(): void
    {
        $oldTerminal = EnvPush::query()->forceCreate([
            'batch' => 'b1', 'server_name' => 'n', 'contents' => 'x', 'preserve_keys' => true,
            'status' => EnvPush::STATUS_APPLIED,
            'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40),
        ]);
        $recentTerminal = EnvPush::query()->forceCreate([
            'batch' => 'b2', 'server_name' => 'n', 'contents' => 'x', 'preserve_keys' => true,
            'status' => EnvPush::STATUS_APPLIED,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        $oldPending = EnvPush::query()->forceCreate([
            'batch' => 'b3', 'server_name' => 'gone', 'contents' => 'x', 'preserve_keys' => true,
            'status' => EnvPush::STATUS_PENDING,
            'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20),
        ]);
        $stuck = EnvPush::query()->forceCreate([
            'batch' => 'b4', 'server_name' => 'n', 'contents' => 'x', 'preserve_keys' => true,
            'status' => EnvPush::STATUS_APPLYING,
            'created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30),
        ]);

        $this->artisan('slipstream:prune')->assertExitCode(0);

        $this->assertNull($oldTerminal->fresh(), 'old terminal push should be deleted');
        $this->assertNotNull($recentTerminal->fresh(), 'recent terminal push kept');
        $this->assertSame(EnvPush::STATUS_CANCELLED, $oldPending->fresh()->status, 'long-pending push expired');
        $this->assertSame(EnvPush::STATUS_FAILED, $stuck->fresh()->status, 'stuck applying push failed by watchdog');
    }
}
