<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentEvent;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Tests\TestCase;

class DeploymentsCommandTest extends TestCase
{
    protected Deployment $deployed;

    protected Deployment $building;

    protected Deployment $failed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deployed = Deployment::query()->create(['server_name' => 'test-node', 'trigger' => 'webhook', 'status' => Deployment::STATUS_DEPLOYED, 'sha' => str_repeat('a', 40), 'branch' => 'main', 'finished_at' => now(), 'duration_ms' => 1500]);
        $this->building = Deployment::query()->create(['server_name' => 'peer-1', 'trigger' => 'manual', 'status' => Deployment::STATUS_BUILDING, 'branch' => 'main', 'started_at' => now()]);
        $this->failed = Deployment::query()->create(['server_name' => 'test-node', 'trigger' => 'manual', 'status' => Deployment::STATUS_FAILED, 'branch' => 'main', 'failure_reason' => 'health check failed', 'finished_at' => now()]);

        foreach ([['step_start', 'fetch', 'info', 'Fetching main…'], ['log', 'fetch', 'info', 'Already up to date'], ['log', 'health', 'error', 'health check failed']] as $i => [$type, $step, $level, $message]) {
            DeploymentEvent::query()->create(['deployment_id' => $this->failed->id, 'sequence' => $i + 1, 'type' => $type, 'step' => $step, 'level' => $level, 'message' => $message, 'created_at' => now()]);
        }
    }

    public function test_failed_filter_shows_only_failed_rows(): void
    {
        $this->artisan('slipstream:deployments --failed')
            ->expectsOutputToContain('health check failed')
            ->doesntExpectOutputToContain('#'.$this->deployed->id.' ')
            ->assertExitCode(0);
    }

    public function test_active_and_server_filters(): void
    {
        $this->artisan('slipstream:deployments --active')
            ->expectsOutputToContain('peer-1')
            ->doesntExpectOutputToContain('test-node')
            ->assertExitCode(0);

        $this->artisan('slipstream:deployments --server=this --json')
            ->expectsOutputToContain('"total": 2')
            ->doesntExpectOutputToContain('peer-1')
            ->assertExitCode(0);
    }

    public function test_an_unknown_status_is_an_error_not_an_empty_list(): void
    {
        $this->artisan('slipstream:deployments --status=bogus')
            ->expectsOutputToContain('Unknown status: bogus')
            ->assertExitCode(1);
    }

    public function test_limit_says_how_much_was_left_out(): void
    {
        $this->artisan('slipstream:deployments --limit=1')
            ->expectsOutputToContain('Showing 1 of 3')
            ->assertExitCode(0);
    }

    public function test_show_prints_details_and_the_log_and_exits_non_zero_for_a_failure(): void
    {
        $this->artisan('slipstream:deployment '.$this->failed->id)
            ->expectsOutputToContain('Deployment #'.$this->failed->id)
            ->expectsOutputToContain('Fetching main')
            ->expectsOutputToContain('[health] health check failed')
            ->assertExitCode(1);
    }

    public function test_tail_limits_the_log_and_says_so(): void
    {
        $this->artisan('slipstream:deployment '.$this->failed->id.' --tail=1')
            ->expectsOutputToContain('last 1 of 3')
            ->doesntExpectOutputToContain('Fetching main')
            ->assertExitCode(1);
    }

    public function test_the_default_is_the_newest_deployment_on_this_node(): void
    {
        // Newest on test-node is the failed one, not peer-1's building row.
        $this->artisan('slipstream:deployment')
            ->expectsOutputToContain('Deployment #'.$this->failed->id)
            ->assertExitCode(1);
    }

    public function test_follow_returns_at_once_for_a_finished_deployment(): void
    {
        $this->artisan('slipstream:deployment '.$this->deployed->id.' --follow')
            ->expectsOutputToContain('serving aaaaaaa')
            ->assertExitCode(0);
    }

    public function test_a_missing_id_is_an_error(): void
    {
        $this->artisan('slipstream:deployment 9999')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_deliveries_lists_the_webhook_log(): void
    {
        WebhookDelivery::query()->create(['provider' => 'github', 'server_name' => 'test-node', 'delivery_id' => 'd-1', 'event' => 'push', 'ref' => 'refs/heads/main', 'sha' => str_repeat('a', 40), 'signature_valid' => true, 'outcome' => 'accepted', 'outcome_detail' => 'Deploy process spawned', 'deployment_id' => $this->deployed->id]);
        WebhookDelivery::query()->create(['provider' => 'github', 'server_name' => 'test-node', 'signature_valid' => false, 'outcome' => 'rejected', 'outcome_detail' => 'Invalid signature/token for provider github']);

        $this->artisan('slipstream:deliveries')
            ->expectsOutputToContain('Invalid signature')
            ->expectsOutputToContain('Deploy process spawned')
            ->assertExitCode(0);

        $this->artisan('slipstream:deliveries --json --limit=1')
            ->expectsOutputToContain('"outcome": "rejected"')
            ->doesntExpectOutputToContain('accepted')
            ->assertExitCode(0);
    }
}
