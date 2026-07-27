<?php

namespace Slipstream\Preview;

use Illuminate\Database\Seeder;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Models\ActivityLog;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentEvent;
use Slipstream\Models\DeploymentHook;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Models\WebhookDelivery;

/**
 * Sample data for the local preview harness (see testbench.yaml). Dev-only —
 * never referenced by the shipped package.
 */
class PreviewSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'slipstream_deployment_events', 'slipstream_deployments', 'slipstream_pipelines',
            'slipstream_servers', 'slipstream_webhook_deliveries',
            'slipstream_deployment_hooks', 'slipstream_activity_log',
        ] as $table) {
            \DB::table($table)->delete();
        }

        Server::query()->create([
            'server_name' => 'web-1', 'hostname' => 'web-1.prod', 'base_url' => 'https://web-1.example.com',
            'current_sha' => 'a1b2c3d4e5f6a7b8c9d0', 'php_version' => '8.4.2', 'disk_free_bytes' => 48_000_000_000,
            'run_migrations' => true, 'enabled' => true, 'paused' => false,
            'horizon_running' => true, 'reverb_running' => null, 'last_heartbeat_at' => now(),
        ]);
        Server::query()->create([
            'server_name' => 'web-2', 'hostname' => 'web-2.prod', 'base_url' => 'https://web-2.example.com',
            'current_sha' => '99887766554433221100', 'php_version' => '8.4.2', 'disk_free_bytes' => 12_000_000_000,
            'run_migrations' => false, 'enabled' => true, 'paused' => false,
            'horizon_running' => true, 'reverb_running' => null, 'last_heartbeat_at' => now()->subSeconds(20),
        ]);

        $pipeline = Pipeline::query()->create([
            'name' => 'main', 'branch_pattern' => 'main', 'auto_deploy' => true, 'enabled' => true, 'server_names' => null,
        ]);

        $deployed = Deployment::query()->create([
            'server_name' => 'web-1', 'sha' => 'a1b2c3d4e5f6a7b8c9d0', 'branch' => 'main',
            'commit_message' => 'Add invoice export and tidy the dashboard header', 'commit_author' => 'Ada Lovelace',
            'trigger' => 'webhook', 'status' => 'deployed', 'duration_ms' => 42_318, 'pipeline_id' => $pipeline->id,
            'started_at' => now()->subMinutes(9), 'finished_at' => now()->subMinutes(8),
        ]);
        foreach ([
            ['step_start', 'fetch', 'info', 'Fetching main…'],
            ['log', 'fetch', 'info', '$ git archive --format=tar -o release.tar a1b2c3d'],
            ['step_end', 'composer', 'info', 'composer install complete (no-dev)'],
            ['log', 'migrate', 'info', 'Skipping migrations — another server holds the fleet migration lock.'],
            ['step_end', 'swap', 'info', 'current -> releases/20260721_a1b2c3d'],
            ['log', 'fpm', 'info', '$ systemctl reload php8.4-fpm'],
            ['log', '', 'info', 'DEPLOY OK in 42318ms — now serving a1b2c3d'],
        ] as $i => [$type, $step, $level, $message]) {
            DeploymentEvent::query()->create([
                'deployment_id' => $deployed->id, 'sequence' => $i + 1,
                'type' => $type, 'step' => $step ?: null, 'level' => $level, 'message' => $message,
                'created_at' => now()->subMinutes(9)->addSeconds($i * 6),
            ]);
        }

        Deployment::query()->create([
            'server_name' => 'web-2', 'sha' => 'a1b2c3d4e5f6a7b8c9d0', 'branch' => 'main',
            'commit_message' => 'Add invoice export and tidy the dashboard header', 'commit_author' => 'Ada Lovelace',
            'trigger' => 'webhook', 'status' => 'deployed', 'duration_ms' => 39_004, 'pipeline_id' => $pipeline->id,
            'warnings' => "Horizon restart failed — workers may keep running old code (program 'horizon').",
            'started_at' => now()->subMinutes(9), 'finished_at' => now()->subMinutes(8),
        ]);
        Deployment::query()->create([
            'server_name' => 'web-1', 'sha' => 'f00dcafe12345678abcd', 'branch' => 'main',
            'commit_message' => 'Broken migration (rolled back)', 'commit_author' => 'Grace Hopper',
            'trigger' => 'webhook', 'status' => 'failed', 'duration_ms' => 8_120, 'pipeline_id' => $pipeline->id,
            'failure_reason' => 'Command failed (exit 1): php artisan migrate --force',
            'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2),
        ]);
        Deployment::query()->create([
            'server_name' => 'web-1', 'branch' => 'main', 'trigger' => 'manual', 'status' => 'building',
            'pipeline_id' => $pipeline->id, 'started_at' => now()->subSeconds(15),
        ]);

        WebhookDelivery::query()->create([
            'provider' => 'github', 'server_name' => 'web-1', 'delivery_id' => 'a1b2-3c4d', 'event' => 'push',
            'ref' => 'refs/heads/main', 'sha' => 'a1b2c3d4e5f6a7b8c9d0', 'signature_valid' => true,
            'matched_pipeline' => true, 'outcome' => 'accepted', 'outcome_detail' => 'Deploy process spawned',
            'deployment_id' => $deployed->id,
        ]);
        WebhookDelivery::query()->create([
            'provider' => 'github', 'server_name' => 'web-1', 'delivery_id' => 'bad-sig-01', 'event' => 'push',
            'ref' => 'refs/heads/main', 'signature_valid' => false, 'matched_pipeline' => false,
            'outcome' => 'rejected', 'outcome_detail' => 'Invalid signature/token for provider github',
        ]);

        // The six shipped defaults (build/migrate/optimize/storage/horizon/reverb)…
        DefaultHooks::seed();
        // …plus one custom hook, to show a user-added script interleaved with them.
        DeploymentHook::query()->create([
            'step' => 'composer', 'position' => 'after', 'name' => 'Warm application cache',
            'script' => '{{ php }} artisan app:warm-cache', 'enabled' => true, 'sort_order' => 45,
        ]);

        ActivityLog::query()->create(['action' => 'deploy.started', 'server_name' => 'web-1', 'meta' => ['deployment' => $deployed->id], 'created_at' => now()->subMinutes(9)]);
        ActivityLog::query()->create(['action' => 'settings.saved', 'server_name' => 'web-1', 'created_at' => now()->subHour()]);
        ActivityLog::query()->create(['action' => 'webhook.secret_rotated', 'server_name' => 'web-1', 'created_at' => now()->subDay()]);
    }
}
