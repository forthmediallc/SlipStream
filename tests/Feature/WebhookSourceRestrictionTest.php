<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

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
