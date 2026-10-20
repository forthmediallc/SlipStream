<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

/**
 * SLIPSTREAM_HOOKS_PATH moves the hook endpoints out from under the dashboard
 * prefix, so an SSO proxy can cover the dashboard without standing in front
 * of the git host's deliveries.
 */
class HooksPathTest extends TestCase
{
    protected string $secret = 'hooks-path-secret';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Routes are registered while the provider boots; slashes on purpose.
        $app['config']->set('slipstream.hooks_path', '/_hooks/');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProcessDetacher::class, new FakeProcessDetacher);
        app(SettingsRepository::class)->set('webhook_secret', $this->secret, encrypted: true);
        Pipeline::query()->create(['name' => 'main', 'branch_pattern' => 'main', 'auto_deploy' => true, 'enabled' => true, 'server_names' => null]);
    }

    public function test_the_hook_endpoints_answer_under_the_configured_path(): void
    {
        $this->postGithubPush('/_hooks/deploy')->assertOk();
        $this->assertSame(1, Deployment::query()->count());

        foreach (['relay', 'env-sync', 'deploy-sync'] as $endpoint) {
            $this->assertNotSame(404, $this->post("/_hooks/{$endpoint}")->status(), $endpoint);
        }
    }

    public function test_the_old_paths_are_gone(): void
    {
        foreach (['deploy', 'relay', 'env-sync', 'deploy-sync'] as $endpoint) {
            $this->post("/slipstream/hooks/{$endpoint}")->assertNotFound();
        }
    }

    public function test_the_dashboard_stays_where_it_was(): void
    {
        Slipstream::auth(fn () => true);

        $this->get('/slipstream')->assertOk();
        $this->assertSame('_hooks', Slipstream::hooksPath());
        $this->assertStringEndsWith('/_hooks/deploy', route('slipstream.hooks.deploy'));
    }

    public function test_urls_shown_to_operators_use_the_hooks_path(): void
    {
        $this->artisan('slipstream:status --json')
            ->expectsOutputToContain('/_hooks/deploy')
            ->assertExitCode(0);

        $this->artisan('slipstream:install')
            ->expectsOutputToContain('/_hooks/deploy')
            ->assertExitCode(0);
    }

    public function test_peers_are_addressed_at_the_hooks_path(): void
    {
        Http::fake();
        app(SettingsRepository::class)->set('relay_enabled', '1');
        Server::query()->create(['server_name' => 'peer-1', 'base_url' => 'https://peer1.example', 'enabled' => true, 'paused' => false]);

        $this->postGithubPush('/_hooks/deploy')->assertOk();
        $delivery = WebhookDelivery::query()->latest('id')->first();
        $this->artisan('slipstream:relay', ['--delivery' => $delivery->id])->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->url() === 'https://peer1.example/_hooks/relay');
    }

    public function test_a_relay_is_recognised_by_its_path(): void
    {
        $this->post('/_hooks/relay', [], ['Content-Type' => 'application/json']);

        $this->assertSame('relay', WebhookDelivery::query()->latest('id')->first()?->provider);
    }

    protected function postGithubPush(string $path)
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
            'X-GitHub-Delivery' => 'd-'.bin2hex(random_bytes(4)),
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', $path, [], [], [], $server, $body);
    }
}
