<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Models\Deployment;
use Slipstream\Notifications\DeployNotifier;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\TestCase;

class NotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $s = app(SettingsRepository::class);
        $s->set('notification_slack_url', 'https://hooks.slack.com/services/T/B/X', encrypted: true);
        $s->set('notification_discord_url', 'https://discord.com/api/webhooks/1/abc', encrypted: true);
        $s->set('notification_webhook_url', 'https://example.test/hook', encrypted: true);
        $s->set('notify_on_success', '1');
        $s->set('notify_on_failure', '1');
    }

    public function test_success_fires_all_channels(): void
    {
        app(DeployNotifier::class)->deploymentFinished($this->deployment(Deployment::STATUS_DEPLOYED));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'hooks.slack.com'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'discord.com'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'example.test'));
    }

    public function test_superseded_deployments_never_notify(): void
    {
        app(DeployNotifier::class)->deploymentFinished($this->deployment(Deployment::STATUS_SUPERSEDED));

        Http::assertNothingSent();
    }

    public function test_failure_respects_notify_on_failure_toggle(): void
    {
        app(SettingsRepository::class)->set('notify_on_failure', '0');

        app(DeployNotifier::class)->deploymentFinished($this->deployment(Deployment::STATUS_FAILED));

        Http::assertNothingSent();
    }

    protected function deployment(string $status): Deployment
    {
        return Deployment::query()->create([
            'server_name' => 'test-node',
            'sha' => str_repeat('d', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => $status,
            'failure_reason' => $status === Deployment::STATUS_SUPERSEDED ? 'Superseded: another deploy held the lock.' : null,
        ]);
    }
}
