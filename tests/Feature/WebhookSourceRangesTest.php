<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Models\ActivityLog;
use Slipstream\Tests\TestCase;
use Slipstream\Webhooks\WebhookSourceRanges;

/**
 * How the provider hook ranges are kept current: from the heartbeat, on a
 * cadence, with an ETag, and without hammering a provider that is down.
 */
class WebhookSourceRangesTest extends TestCase
{
    public function test_the_heartbeat_downloads_due_ranges_and_sends_the_etag_next_time(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['github']);
        Http::fake(['api.github.com/*' => Http::sequence()
            ->push(['hooks' => ['140.82.112.0/20']], 200, ['ETag' => '"v1"'])
            ->push('', 304)]);

        $this->artisan('slipstream:heartbeat')
            ->expectsOutputToContain('1 ranges (changed)')
            ->assertExitCode(0);
        $this->artisan('slipstream:heartbeat')->assertExitCode(0);
        Http::assertSentCount(1);

        $this->travel(7)->hours();
        $this->artisan('slipstream:heartbeat')
            ->expectsOutputToContain('unchanged (1 ranges)')
            ->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"v1"') && $request->hasHeader('User-Agent', 'Slipstream'));
        $this->assertSame(['140.82.112.0/20'], app(WebhookSourceRanges::class)->stored()['github']['cidrs']);
    }

    public function test_a_failed_attempt_is_not_retried_every_minute(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['github']);
        Http::fake(['api.github.com/*' => Http::response('', 500)]);

        $this->artisan('slipstream:heartbeat')->assertExitCode(0);
        $this->travel(1)->minutes();
        $this->artisan('slipstream:heartbeat')->assertExitCode(0);
        Http::assertSentCount(1);

        $this->travel(16)->minutes();
        $this->artisan('slipstream:heartbeat')->assertExitCode(0);
        Http::assertSentCount(2);
    }

    public function test_poll_mode_never_downloads_ranges(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['github']);
        config()->set('slipstream.trigger.mode', 'poll');
        config()->set('slipstream.trigger.poll_jitter', 0);
        Http::fake();

        $this->artisan('slipstream:heartbeat')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_a_refresh_failure_is_in_the_activity_log_once_per_streak(): void
    {
        Http::fake(['api.github.com/*' => Http::response('', 503)]);
        $ranges = app(WebhookSourceRanges::class);

        $ranges->refresh('github');
        $ranges->refresh('github');

        $this->assertSame(1, ActivityLog::query()->where('action', 'webhook.ranges_refresh_failed')->count());
    }

    public function test_the_command_refreshes_and_shows(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['github', '10.0.0.0/8']);
        Http::fake(['api.github.com/*' => Http::sequence()
            ->push(['hooks' => ['140.82.112.0/20', '143.55.64.0/20']])
            ->push('', 500)]);

        $this->artisan('slipstream:webhook-ranges show')
            ->expectsOutputToContain('never downloaded')
            ->assertExitCode(0);

        $this->artisan('slipstream:webhook-ranges refresh')
            ->expectsOutputToContain('2 ranges (changed)')
            ->assertExitCode(0);
        $this->assertTrue(ActivityLog::query()->where('action', 'webhook.ranges_updated')->exists());

        $this->artisan('slipstream:webhook-ranges show')
            ->expectsOutputToContain('2 ranges · downloaded')
            ->assertExitCode(0);

        $this->artisan('slipstream:webhook-ranges refresh gitlab')
            ->expectsOutputToContain('not a provider keyword')
            ->assertExitCode(1);

        $this->artisan('slipstream:webhook-ranges refresh')
            ->expectsOutputToContain('kept 2 ranges')
            ->assertExitCode(1);
    }
}
