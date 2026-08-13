<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Models\Deployment;
use Slipstream\Notifications\DeployNotifier;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\TestCase;

class DeployNotifierTest extends TestCase
{
    /**
     * failure_reason carries the tail of raw command output below its first
     * line — content the README plainly says routinely echoes secrets, and
     * which is otherwise confined to the 0600 deploy log and the gated
     * dashboard. Notifications leave the host, so only the summary line may
     * travel with them.
     */
    public function test_notifications_carry_only_the_first_line_of_the_failure_reason(): void
    {
        Http::fake();

        $settings = app(SettingsRepository::class);
        $settings->set('notification_slack_url', 'https://hooks.slack.example/T000/B000/xyz');
        $settings->set('notification_webhook_url', 'https://ops.example/hook');

        $deployment = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'status' => Deployment::STATUS_FAILED,
            'failure_reason' => "Command failed (exit 1): composer install\n  │ COMPOSER_AUTH={\"github-oauth\":{\"github.com\":\"ghp_secret\"}}\n  │ authentication failed",
        ]);

        app(DeployNotifier::class)->deploymentFinished($deployment);

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            $this->assertStringContainsString('Command failed (exit 1): composer install', $body);
            $this->assertStringNotContainsString('ghp_secret', $body, 'output tail must never leave the host');
            $this->assertStringContainsString('full output in the deploy log', $body);

            return true;
        });
    }

    public function test_a_single_line_reason_travels_unchanged(): void
    {
        Http::fake();

        app(SettingsRepository::class)->set('notification_slack_url', 'https://hooks.slack.example/T000/B000/xyz');

        $deployment = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_FAILED,
            'failure_reason' => 'Deploy lock is held by another deploy.',
        ]);

        app(DeployNotifier::class)->deploymentFinished($deployment);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('Deploy lock is held by another deploy.', json_encode($request->data()));
            $this->assertStringNotContainsString('full output in the deploy log', json_encode($request->data()));

            return true;
        });
    }
}
