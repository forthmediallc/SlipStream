<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Deploy\StuckDeploymentsSweeper;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\Activity;
use Slipstream\Support\AuditLog;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

/**
 * The audit log is the host's log channel, so the assertions read the file a
 * real single-file channel writes rather than mocking the facade.
 */
class AuditLogTest extends TestCase
{
    protected string $secret = 'audit-test-secret';

    protected string $logDir;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->logDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-audit-'.bin2hex(random_bytes(4));
        @mkdir($this->logDir, 0777, true);

        $app['config']->set('logging.channels.default-test', ['driver' => 'single', 'path' => $this->logDir.'/default.log']);
        $app['config']->set('logging.channels.audit-test', ['driver' => 'single', 'path' => $this->logDir.'/audit.log']);
        $app['config']->set('logging.default', 'default-test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProcessDetacher::class, new FakeProcessDetacher);
        app(SettingsRepository::class)->set('webhook_secret', $this->secret, encrypted: true);
        Pipeline::query()->create([
            'name' => 'main', 'branch_pattern' => 'main', 'auto_deploy' => true, 'enabled' => true, 'server_names' => null,
        ]);
    }

    protected function tearDown(): void
    {
        // Close the log files first. Windows only marks an open file for
        // deletion, and a directory holding one cannot be removed.
        if ($this->app) {
            foreach (['default-test', 'audit-test'] as $channel) {
                $this->app['log']->channel($channel)->getLogger()->close();
            }
        }

        parent::tearDown();

        $this->removeTree($this->logDir);
    }

    public function test_off_by_default_outside_pci_mode(): void
    {
        $this->assertFalse(AuditLog::enabled());

        $this->postGithubPush('d-off')->assertOk();
        Activity::log('settings.saved');

        $this->assertFileDoesNotExist($this->logDir.'/default.log');
    }

    public function test_pci_mode_writes_delivery_deployment_and_activity_events(): void
    {
        config()->set('slipstream.pci.enabled', true);

        $this->postGithubPush('d-pci')->assertOk();
        Activity::log('pci.enabled', ['via' => 'test']);

        $log = $this->defaultLog();
        $this->assertStringContainsString('slipstream.webhook.accepted', $log);
        $this->assertStringContainsString('slipstream.deployment.pending', $log);
        $this->assertStringContainsString('slipstream.activity.pci.enabled', $log);
        $this->assertStringContainsString('"node":"test-node"', $log);
    }

    public function test_a_status_change_is_one_event_and_an_unrelated_update_is_none(): void
    {
        config()->set('slipstream.audit.enabled', true);

        $deployment = Deployment::query()->create([
            'server_name' => 'test-node', 'trigger' => Deployment::TRIGGER_MANUAL, 'status' => Deployment::STATUS_PENDING,
        ]);
        $deployment->update(['status' => Deployment::STATUS_BUILDING, 'started_at' => now()]);
        $deployment->update(['commit_message' => 'no status change']);

        $log = $this->defaultLog();
        $this->assertSame(1, substr_count($log, 'slipstream.deployment.pending'));
        $this->assertSame(1, substr_count($log, 'slipstream.deployment.building'));
        $this->assertSame(2, substr_count($log, 'slipstream.deployment.'));
    }

    public function test_a_rejected_delivery_is_an_event_too(): void
    {
        config()->set('slipstream.audit.enabled', true);

        $this->postGithubPush('d-bad', secret: 'WRONG')->assertStatus(401);

        $this->assertStringContainsString('slipstream.webhook.rejected', $this->defaultLog());
    }

    public function test_the_watchdog_records_the_rows_it_fails(): void
    {
        config()->set('slipstream.audit.enabled', true);

        $stuck = Deployment::query()->create([
            'server_name' => 'test-node', 'trigger' => Deployment::TRIGGER_WEBHOOK,
            'status' => Deployment::STATUS_BUILDING, 'started_at' => now()->subHours(5),
        ]);

        $swept = app(StuckDeploymentsSweeper::class)->sweep('test-node');

        $this->assertSame(1, $swept['building']);
        $this->assertSame(Deployment::STATUS_FAILED, $stuck->fresh()->status);
        $this->assertStringContainsString('"via":"watchdog"', $this->defaultLog());
        $this->assertStringContainsString('"deployment":'.$stuck->id, $this->defaultLog());
    }

    public function test_a_configured_channel_is_used_instead_of_the_default(): void
    {
        config()->set('slipstream.audit.enabled', true);
        config()->set('slipstream.audit.channel', 'audit-test');

        Activity::log('settings.saved');

        $this->assertStringContainsString('slipstream.activity.settings.saved', (string) file_get_contents($this->logDir.'/audit.log'));
        $this->assertFileDoesNotExist($this->logDir.'/default.log');
    }

    public function test_an_explicit_off_wins_over_pci_mode(): void
    {
        config()->set('slipstream.pci.enabled', true);
        config()->set('slipstream.audit.enabled', false);

        $this->assertFalse(AuditLog::enabled());
    }

    protected function defaultLog(): string
    {
        $path = $this->logDir.'/default.log';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    protected function postGithubPush(string $deliveryId, ?string $secret = null)
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
        ]);

        return $this->call('POST', '/slipstream/hooks/deploy', [], [], [], $server, $body);
    }
}
