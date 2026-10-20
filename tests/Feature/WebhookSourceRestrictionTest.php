<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;
use Slipstream\Webhooks\WebhookSourceRanges;

class WebhookSourceRestrictionTest extends TestCase
{
    protected string $secret = 'source-test-secret';

    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detacher = new FakeProcessDetacher;
        $this->app->instance(ProcessDetacher::class, $this->detacher);
        app(SettingsRepository::class)->set('webhook_secret', $this->secret, encrypted: true);

        Pipeline::query()->create([
            'name' => 'main',
            'branch_pattern' => 'main',
            'auto_deploy' => true,
            'enabled' => true,
            'server_names' => null,
        ]);
    }

    public function test_a_delivery_from_outside_the_allow_list_is_refused_and_recorded(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['140.82.112.0/20', '2a0a:a440::/29']);

        $this->postGithubPush('d-1', from: '203.0.113.9')->assertStatus(403);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame('rejected', $delivery->outcome);
        $this->assertStringContainsString('203.0.113.9', $delivery->outcome_detail);
        $this->assertFalse($delivery->signature_valid);

        $this->assertSame(0, Deployment::query()->count());
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_a_delivery_from_an_allowed_range_proceeds(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['140.82.112.0/20', '2a0a:a440::/29']);

        $this->postGithubPush('d-v4', from: '140.82.115.5')->assertOk();
        $this->postGithubPush('d-v6', from: '2a0a:a440::1')->assertOk();

        $this->assertSame(2, Deployment::query()->count());
    }

    public function test_no_allow_list_means_any_source(): void
    {
        config()->set('slipstream.webhook.allowed_ips', []);

        $this->postGithubPush('d-open', from: '203.0.113.9')->assertOk();

        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_a_comma_separated_string_is_accepted(): void
    {
        // A published config may hand the env value through untouched.
        config()->set('slipstream.webhook.allowed_ips', '140.82.112.0/20, 203.0.113.0/24');

        $this->postGithubPush('d-str', from: '203.0.113.9')->assertOk();
    }

    public function test_a_bad_signature_from_an_allowed_source_is_still_rejected(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['203.0.113.0/24']);

        $this->postGithubPush('d-sig', from: '203.0.113.9', secret: 'WRONG')->assertStatus(401);

        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_a_provider_keyword_expands_to_its_published_ranges(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['github']);
        Http::fake(['api.github.com/*' => Http::response(['hooks' => ['140.82.112.0/20', '2a0a:a440::/29']], 200, ['ETag' => '"v1"'])]);

        app(WebhookSourceRanges::class)->refresh('github');

        $this->postGithubPush('d-in', from: '140.82.115.5')->assertOk();
        $this->postGithubPush('d-out', from: '203.0.113.9')->assertStatus(403);

        $stored = app(WebhookSourceRanges::class)->stored()['github'];
        $this->assertSame(['140.82.112.0/20', '2a0a:a440::/29'], $stored['cidrs']);
        $this->assertSame('"v1"', $stored['etag']);
    }

    public function test_bitbucket_ranges_are_the_egress_ranges_of_bitbucket_only(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['bitbucket']);
        Http::fake(['ip-ranges.atlassian.com*' => Http::response(['items' => [
            ['cidr' => '104.192.136.0/21', 'product' => ['bitbucket'], 'direction' => ['egress']],
            ['cidr' => '13.52.5.0/25', 'product' => ['jira'], 'direction' => ['egress']],
            ['cidr' => '18.246.31.128/25', 'product' => ['bitbucket'], 'direction' => ['ingress']],
        ]])]);

        app(WebhookSourceRanges::class)->refresh('bitbucket');

        $this->assertSame(['104.192.136.0/21'], app(WebhookSourceRanges::class)->stored()['bitbucket']['cidrs']);
        $this->postGithubPush('d-bb', from: '104.192.137.4')->assertOk();
    }

    /**
     * Never an empty "any" list: a keyword nobody downloaded yet matches
     * nothing, and the rejected row says what to run.
     */
    public function test_a_keyword_never_downloaded_refuses_every_delivery_and_says_why(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['github']);

        $this->postGithubPush('d-none', from: '140.82.115.5')->assertStatus(403);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertStringContainsString('github ranges have never been downloaded', $delivery->outcome_detail);
        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_a_failed_download_keeps_the_last_good_list(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['github']);
        app(SettingsRepository::class)->set(WebhookSourceRanges::SETTING, ['github' => [
            'cidrs' => ['140.82.112.0/20'], 'etag' => '"v1"', 'fetched_at' => now()->subHours(7)->toIso8601String(),
            'last_attempt_at' => null, 'last_error' => null,
        ]]);
        Http::fake(['api.github.com/*' => Http::response('', 500)]);

        $result = app(WebhookSourceRanges::class)->refresh('github');

        $this->assertFalse($result['ok']);
        $stored = app(WebhookSourceRanges::class)->stored()['github'];
        $this->assertSame(['140.82.112.0/20'], $stored['cidrs']);
        $this->assertSame('HTTP 500', $stored['last_error']);
        $this->postGithubPush('d-kept', from: '140.82.115.5')->assertOk();
    }

    public function test_keywords_and_literal_addresses_mix(): void
    {
        config()->set('slipstream.webhook.allowed_ips', ['github', '10.0.0.0/8']);
        Http::fake(['api.github.com/*' => Http::response(['hooks' => ['140.82.112.0/20']])]);
        app(WebhookSourceRanges::class)->refresh('github');

        $this->postGithubPush('d-lit', from: '10.1.2.3')->assertOk();
        $this->postGithubPush('d-gh', from: '140.82.115.5')->assertOk();
        $this->postGithubPush('d-no', from: '203.0.113.9')->assertStatus(403);
    }

    protected function postGithubPush(string $deliveryId, string $from, ?string $secret = null)
    {
        $sha = str_repeat('a', 40);
        $body = json_encode([
            'ref' => 'refs/heads/main',
            'after' => $sha,
            'head_commit' => ['id' => $sha, 'message' => 'msg', 'author' => ['name' => 'Dev']],
            'repository' => ['full_name' => 'org/app'],
            'pusher' => ['name' => 'dev'],
        ], JSON_THROW_ON_ERROR);

        $server = $this->transformHeadersToServerVars([
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => $deliveryId,
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? $this->secret),
            'Content-Type' => 'application/json',
        ]) + ['REMOTE_ADDR' => $from];

        return $this->call('POST', '/slipstream/hooks/deploy', [], [], [], $server, $body);
    }
}
