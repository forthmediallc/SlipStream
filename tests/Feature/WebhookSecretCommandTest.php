<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\ActivityLog;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\TestCase;

class WebhookSecretCommandTest extends TestCase
{
    public function test_rotate_replaces_the_secret_and_reveal_prints_it(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('webhook_secret', 'old-secret-value', encrypted: true);

        $this->artisan('slipstream:webhook-secret --rotate')
            ->expectsOutputToContain('rotated')
            ->doesntExpectOutputToContain('old-secret-value')
            ->assertExitCode(0);

        $new = $settings->webhookSecret();
        $this->assertNotNull($new);
        $this->assertNotSame('old-secret-value', $new);

        $this->artisan('slipstream:webhook-secret --reveal')
            ->expectsOutput($new)
            ->assertExitCode(0);

        $this->assertTrue(ActivityLog::query()->where('action', 'webhook.secret_rotated')->exists());
        $this->assertTrue(ActivityLog::query()->where('action', 'webhook.secret_revealed')->exists());
    }

    public function test_without_reveal_the_value_is_never_printed(): void
    {
        app(SettingsRepository::class)->set('webhook_secret', 'old-secret-value', encrypted: true);

        $this->artisan('slipstream:webhook-secret')
            ->expectsOutputToContain('pass --reveal')
            ->doesntExpectOutputToContain('old-secret-value')
            ->assertExitCode(0);
    }

    public function test_a_missing_secret_is_an_error(): void
    {
        $this->artisan('slipstream:webhook-secret --reveal')
            ->expectsOutputToContain('slipstream:install')
            ->assertExitCode(1);
    }
}
