<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

/**
 * The delivery row is written before the signature is checked so rejected
 * deliveries stay auditable — which means unverified body fields reach columns.
 * They must be clamped to the column sizes: on MySQL/Postgres an overlong value
 * raises SQLSTATE 22001, turning an unauthenticated POST into a 500. (SQLite
 * doesn't enforce lengths, so these assert on what actually got stored.)
 */
class WebhookInputBoundsTest extends TestCase
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
            'branch_pattern' => '*',
            'auto_deploy' => true,
            'enabled' => true,
            'server_names' => null,
        ]);
    }

    public function test_unsigned_oversized_fields_are_clamped_to_their_columns(): void
    {
        $body = json_encode([
            'ref' => 'refs/heads/'.str_repeat('r', 5_000),
            'after' => str_repeat('a', 500),
            'repository' => ['full_name' => str_repeat('n', 5_000)],
            'pusher' => ['name' => str_repeat('p', 5_000)],
        ], JSON_THROW_ON_ERROR);

        // No valid signature: this is what any anonymous POST can reach.
        $this->postHook($body, [
            'X-GitHub-Event' => str_repeat('e', 5_000),
            'X-GitHub-Delivery' => str_repeat('d', 5_000),
            'Content-Type' => 'application/json',
        ])->assertStatus(401);

        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->assertSame(255, mb_strlen($delivery->ref));
        $this->assertSame(64, mb_strlen($delivery->sha));
        $this->assertSame(255, mb_strlen($delivery->event));
        $this->assertSame(255, mb_strlen($delivery->delivery_id));
        $this->assertSame(255, mb_strlen($delivery->payload_meta['repository']));
        $this->assertSame(255, mb_strlen($delivery->payload_meta['pusher']));
        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_signed_push_with_a_malformed_sha_is_rejected_not_truncated(): void
    {
        $sha = str_repeat('a', 200);
        $body = json_encode([
            'ref' => 'refs/heads/main',
            'after' => $sha,
            'head_commit' => ['id' => $sha],
        ], JSON_THROW_ON_ERROR);

        $this->postHook($body, [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-longsha',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
            'Content-Type' => 'application/json',
        ])->assertStatus(422);

        // A clamped 200-char 'a' string would still look like a valid hex object
        // id — deploying a truncated SHA is worse than refusing the delivery.
        $this->assertSame(0, Deployment::query()->count());
        $this->assertCount(0, $this->detacher->calls);
        $this->assertSame('rejected', WebhookDelivery::query()->firstOrFail()->outcome);
    }

    public function test_long_but_valid_payload_still_deploys(): void
    {
        $sha = str_repeat('a', 40);
        $body = json_encode([
            'ref' => 'refs/heads/main',
            'after' => $sha,
            'head_commit' => [
                'id' => $sha,
                'message' => str_repeat('m', 5_000),
                'author' => ['name' => str_repeat('x', 5_000)],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postHook($body, [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-long-message',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
            'Content-Type' => 'application/json',
        ])->assertOk();

        $deployment = Deployment::query()->firstOrFail();
        $this->assertSame($sha, $deployment->sha);
        $this->assertSame(255, mb_strlen($deployment->commit_message));
        $this->assertSame(255, mb_strlen($deployment->commit_author));
    }

    /**
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
}
