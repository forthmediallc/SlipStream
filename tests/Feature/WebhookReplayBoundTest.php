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
 * A provider HMAC signs the body and nothing else — no timestamp — and the
 * delivery id the ingress dedupes on rides in an unsigned header. So a captured
 * delivery can be replayed indefinitely by changing that header. Webhook deploys
 * are therefore idempotent per commit: a delivery naming the SHA this node
 * already deployed successfully is a no-op.
 */
class WebhookReplayBoundTest extends TestCase
{
    protected string $secret = 'test-secret-value';

    protected string $sha;

    protected FakeProcessDetacher $detacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sha = str_repeat('a', 40);
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

    public function test_replay_with_a_fresh_delivery_id_does_not_redeploy_the_live_commit(): void
    {
        $this->deployed($this->sha);

        // Same signed body, new (unsigned) delivery id — the delivery-id dedupe
        // cannot see this, so the SHA check has to.
        $this->postPush('replayed-id')->assertOk();

        $this->assertSame(1, Deployment::query()->count(), 'a replay must not deploy again');
        $this->assertCount(0, $this->detacher->calls);
        $this->assertSame('ignored', WebhookDelivery::query()->latest('id')->first()->outcome);
    }

    public function test_a_new_commit_still_deploys(): void
    {
        $this->deployed(str_repeat('b', 40));

        $this->postPush('d-new')->assertOk();

        $this->assertSame(2, Deployment::query()->count());
        $this->assertCount(1, $this->detacher->calls);
    }

    public function test_redelivery_after_a_failed_deploy_still_works(): void
    {
        // The provider's "redeliver" button is how you retry a failed deploy —
        // the bound must not take that away.
        $this->deployed($this->sha, Deployment::STATUS_FAILED);

        $this->postPush('d-retry')->assertOk();

        $this->assertSame(2, Deployment::query()->count());
        $this->assertCount(1, $this->detacher->calls);
    }

    public function test_roll_forward_after_a_rollback_still_works(): void
    {
        // Deployed the commit, then rolled back: the newest finished deploy is
        // the rollback, so re-sending the webhook rolls forward again.
        $this->deployed($this->sha);
        $this->deployed(str_repeat('c', 40), Deployment::STATUS_ROLLED_BACK);

        $this->postPush('d-forward')->assertOk();

        $this->assertSame(3, Deployment::query()->count());
        $this->assertCount(1, $this->detacher->calls);
    }

    public function test_opt_out_restores_redeploy_of_the_same_sha(): void
    {
        config()->set('slipstream.webhook.allow_redeploy_same_sha', true);
        $this->deployed($this->sha);

        $this->postPush('d-again')->assertOk();

        $this->assertSame(2, Deployment::query()->count());
    }

    protected function deployed(string $sha, string $status = Deployment::STATUS_DEPLOYED): Deployment
    {
        return Deployment::query()->create([
            'server_name' => 'test-node',
            'sha' => $sha,
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'status' => $status,
            'finished_at' => now(),
        ]);
    }

    protected function postPush(string $deliveryId)
    {
        $body = json_encode([
            'ref' => 'refs/heads/main',
            'after' => $this->sha,
            'head_commit' => ['id' => $this->sha, 'message' => 'msg'],
        ], JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            '/slipstream/hooks/deploy',
            [], [], [],
            $this->transformHeadersToServerVars([
                'X-GitHub-Event' => 'push',
                'X-GitHub-Delivery' => $deliveryId,
                'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
                'Content-Type' => 'application/json',
            ]),
            $body
        );
    }
}
