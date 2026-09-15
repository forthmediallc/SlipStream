<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

class WebhookIngressTest extends TestCase
{
    protected string $secret = 'test-secret-value';

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

    public function test_hook_routes_are_excluded_from_csrf_and_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('slipstream.hooks.deploy');

        $this->assertNotNull($route, 'deploy hook route must exist');
        $this->assertContains(
            ValidateCsrfToken::class,
            $route->excludedMiddleware() ?? [],
            'webhook route must exclude CSRF or every provider POST 419s'
        );
        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle')),
            'webhook route must be throttled'
        );
    }

    public function test_valid_github_push_creates_deployment_and_spawns_deploy(): void
    {
        $response = $this->postSigned($this->githubPush('d-1'));

        $response->assertOk();
        $this->assertSame(1, Deployment::query()->count());

        $delivery = WebhookDelivery::query()->first();
        $this->assertTrue($delivery->signature_valid);
        $this->assertSame('accepted', $delivery->outcome);
        $this->assertSame('test-node', $delivery->server_name);
        $this->assertNotNull($delivery->deployment_id);

        $this->assertCount(1, $this->detacher->calls);
        $this->assertContains('slipstream:deploy', $this->detacher->calls[0]['args']);
    }

    public function test_invalid_signature_is_rejected_and_recorded(): void
    {
        [$body, $headers] = $this->githubPush('d-bad');
        $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, 'WRONG-SECRET');

        $this->postHook($body, $headers)->assertStatus(401);

        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame('rejected', WebhookDelivery::query()->first()->outcome);
    }

    public function test_ping_event_is_acknowledged_without_deploying(): void
    {
        [$body, $headers] = $this->githubPush('d-ping', event: 'ping');

        $this->postHook($body, $headers)->assertOk();

        $this->assertSame(0, Deployment::query()->count());
        $this->assertCount(0, $this->detacher->calls);
    }

    public function test_oversized_body_is_rejected_413(): void
    {
        config()->set('slipstream.webhook.max_body_bytes', 10);

        [$body, $headers] = $this->githubPush('d-big');

        $this->postHook($body, $headers)->assertStatus(413);

        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_duplicate_delivery_id_is_not_deployed_twice(): void
    {
        $this->postSigned($this->githubPush('dup-1'))->assertOk();
        $this->assertSame(1, Deployment::query()->count());

        // Same delivery id replayed — must be treated as a duplicate.
        $this->postSigned($this->githubPush('dup-1'))->assertOk();
        $this->assertSame(1, Deployment::query()->count(), 'a duplicate delivery must not deploy again');

        $this->assertSame('ignored', WebhookDelivery::query()->latest('id')->first()->outcome);
    }

    /**
     * The detacher throws when it cannot resolve a PHP CLI (or write its log).
     * That has to become a failed row and a spawn_failed delivery — a 500 the
     * provider shows in red — never an "accepted" delivery for a deploy that
     * will sit in "pending".
     */
    public function test_a_spawn_failure_is_recorded_on_the_row_and_the_delivery(): void
    {
        $this->detacher->throw = true;

        $this->postSigned($this->githubPush('d-spawn'))->assertStatus(500);

        $deployment = Deployment::query()->sole();
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->status);
        $this->assertStringContainsString('Spawn failed: spawn failed (fake)', $deployment->failure_reason);
        $this->assertNotNull($deployment->finished_at);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame('spawn_failed', $delivery->outcome);
        $this->assertSame($deployment->id, $delivery->deployment_id);
    }

    /**
     * @param  array{0: string, 1: array<string, string>}  $signed
     */
    protected function postSigned(array $signed)
    {
        [$body, $headers] = $signed;

        return $this->postHook($body, $headers);
    }

    /**
     * POST a raw body with headers. Headers must go through the server-var
     * transform — passing them any other way to call() silently drops them.
     *
     * @param  array<string, string>  $headers
     */
    protected function postHook(string $body, array $headers)
    {
        return $this->call(
            'POST',
            '/slipstream/hooks/deploy',
            [], [], [],
            $this->transformHeadersToServerVars($headers),
            $body
        );
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    protected function githubPush(string $deliveryId, string $event = 'push'): array
    {
        $sha = str_repeat('a', 40);
        $body = json_encode([
            'ref' => 'refs/heads/main',
            'after' => $sha,
            'head_commit' => ['id' => $sha, 'message' => 'msg', 'author' => ['name' => 'Dev']],
            'repository' => ['full_name' => 'org/app'],
            'pusher' => ['name' => 'dev'],
        ], JSON_THROW_ON_ERROR);

        return [$body, [
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $deliveryId,
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
            'Content-Type' => 'application/json',
        ]];
    }
}
