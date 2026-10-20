<?php

namespace Slipstream\Tests\Feature;

use RuntimeException;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Deploy\Deployer;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentEvent;
use Slipstream\Models\Server;
use Slipstream\Release\Core\ArtifactRefused;
use Slipstream\Release\ReleaseVerifier;
use Slipstream\Tests\Support\ArtifactFixture;
use Slipstream\Tests\Support\RecordingDeployer;
use Slipstream\Tests\TestCase;

/**
 * The real deployer, in artifact mode, against a store on disk: the whole
 * chain from pointer to swapped release, and every way it refuses. The swap
 * is a symlink move, so this runs on POSIX (under WSL here).
 */
class ArtifactDeployTest extends TestCase
{
    protected const OLD = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    protected ArtifactFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks and mv -T.');
        }

        $this->fixture = (new ArtifactFixture)->configure()->writeCheckout()->package();
        $this->fixture->publish();
        config()->set('slipstream.services.restart_fpm', false);
        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false]);

        // What a node has before: an older release, live.
        $old = $this->fixture->appRoot.'/releases/20261001_000000_eeeeeee';
        mkdir($old, 0777, true);
        file_put_contents($old.'/RELEASE_SHA', self::OLD."\n");
        touch($old, time() - 3600);
        symlink($old, $this->fixture->appRoot.'/current');

        // The stored defaults, "Build assets" among them, as every install has.
        DefaultHooks::seed();
    }

    protected function tearDown(): void
    {
        // Unset when setUp() skipped before making it.
        if (isset($this->fixture)) {
            $this->fixture->remove();
        }

        parent::tearDown();
    }

    public function test_a_signed_artifact_deploys_end_to_end_without_git_composer_or_npm(): void
    {
        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production']);

        $deployer->run();

        $deployment->refresh();
        $this->assertSame(Deployment::STATUS_DEPLOYED, $deployment->status);
        $live = realpath($this->fixture->appRoot.'/current');
        $this->assertSame($this->fixture->sha, trim((string) file_get_contents($live.'/RELEASE_SHA')));
        $this->assertFileExists($live.'/.slipstream/manifest.json');
        $this->assertFileExists($live.'/.slipstream/manifest.sig');
        $this->assertFileExists($live.'/.slipstream/pointer.json');
        $this->assertFileExists($live.'/vendor/slipstream/slipstream/composer.json');
        $this->assertTrue(is_link($live.'/.env'), 'shared links are made as always');
        $this->assertTrue(app(ReleaseVerifier::class)->verify($live)['ok'], 'the live release matches its manifest');

        foreach ($deployer->commands() as $command) {
            $command = str_replace(Deployer::ARTIFACT_GUARD."\n", '', $command);
            foreach (['git ', 'composer', 'npm'] as $tool) {
                $this->assertStringNotContainsString($tool, $command, "a node in artifact mode ran {$tool}: {$command}");
            }
        }
        $this->assertSame([
            'sequence' => $this->fixture->pointer->sequence,
            'manifest_sha256' => $this->fixture->pointer->manifestSha256,
            'key_id' => 'test-key',
        ], $deployment->meta['artifact']);
        $this->assertSame([], glob($this->fixture->appRoot.'/releases/.incoming-*'), 'nothing left staged');
    }

    public function test_a_tampered_tarball_is_refused_and_nothing_is_swapped(): void
    {
        $this->fixture->tamperArchive();

        $this->assertRefused('archive', 'does not match the signed manifest');
    }

    public function test_a_bad_manifest_signature_is_refused_and_nothing_is_swapped(): void
    {
        $this->fixture->resignManifest($this->fixture->otherSigner());

        $this->assertRefused('statement', 'signature does not verify');
    }

    public function test_a_bad_pointer_signature_is_refused_and_nothing_is_swapped(): void
    {
        $this->fixture->resignPointer($this->fixture->otherSigner());

        $this->assertRefused('pointer', 'signature does not verify');
    }

    public function test_a_file_whose_hash_does_not_match_the_manifest_is_refused_and_nothing_is_swapped(): void
    {
        $this->fixture->publishWithFileHashMismatch();

        $this->assertRefused('files', 'does not match the manifest');
    }

    public function test_an_artifact_built_for_a_newer_php_is_refused_and_an_older_one_only_warns(): void
    {
        $this->fixture->republishManifest(fn (array $m) => ['php' => '99.0.0'] + $m);
        $this->assertRefused('php', 'built for PHP 99.0; this node runs '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);

        $this->fixture->republishManifest(fn (array $m) => ['php' => '7.4.33'] + $m);
        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production']);
        $deployer->run();

        $this->assertSame(Deployment::STATUS_DEPLOYED, $deployment->refresh()->status);
        $this->assertTrue(DeploymentEvent::query()->where('deployment_id', $deployment->id)->where('message', 'like', '%Newer usually works%')->exists());
    }

    public function test_a_commit_deploys_by_its_own_signature_without_a_pointer(): void
    {
        $this->fixture->deletePointer();
        [$deployer, $deployment] = $this->deployer(substr($this->fixture->sha, 0, 8));

        $deployer->run();

        $this->assertSame(Deployment::STATUS_DEPLOYED, $deployment->refresh()->status);
        $this->assertSame($this->fixture->sha, $deployment->sha, 'the short sha was expanded');
        $this->assertNull($deployment->meta['artifact']['sequence']);
        $this->assertFileDoesNotExist(realpath($this->fixture->appRoot.'/current').'/.slipstream/pointer.json');
    }

    public function test_webhook_mode_refuses_artifact_deploys_before_touching_the_store(): void
    {
        config()->set('slipstream.trigger.mode', 'webhook');
        [$deployer, $deployment] = $this->deployer($this->fixture->sha);

        try {
            $deployer->run();
            $this->fail('deployed an artifact in webhook mode');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SLIPSTREAM_TRIGGER=poll', $e->getMessage());
        }

        $this->assertSame(Deployment::STATUS_FAILED, $deployment->refresh()->status);
        $this->assertSame([], glob($this->fixture->appRoot.'/releases/.incoming-*'));
    }

    public function test_a_failure_after_verification_keeps_the_release_for_inspection(): void
    {
        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production']);
        $deployer->runner->failOn = ['slipstream:healthcheck'];

        try {
            $deployer->run();
            $this->fail('deployed past a failing health check');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(Deployment::STATUS_FAILED, $deployment->refresh()->status);
        $this->assertCount(1, glob($this->fixture->appRoot.'/releases/*.failed'), 'a verified release that failed later is kept');
        $this->assertSame(self::OLD, trim((string) file_get_contents($this->fixture->appRoot.'/current/RELEASE_SHA')));
    }

    protected function assertRefused(string $stage, string $reason): void
    {
        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production']);

        try {
            $deployer->run();
            $this->fail("deployed a release that should be refused at the {$stage} stage");
        } catch (ArtifactRefused $e) {
            $this->assertSame($stage, $e->stage);
            $this->assertStringContainsString($reason, $e->getMessage());
        }

        $deployment->refresh();
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->status);
        $this->assertStringContainsString("Refusing artifact ({$stage})", (string) $deployment->failure_reason);
        $this->assertSame(self::OLD, trim((string) file_get_contents($this->fixture->appRoot.'/current/RELEASE_SHA')), 'nothing swapped');
        $this->assertSame(['20261001_000000_eeeeeee'], array_map('basename', glob($this->fixture->appRoot.'/releases/*') ?: []), 'no new release, nothing kept as .failed');
        $this->assertSame([], glob($this->fixture->appRoot.'/releases/.incoming-*'), 'the staging directory is gone');
        $this->assertSame([], glob($this->fixture->appRoot.'/releases/*.tar.gz'), 'no tarball left behind');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0: RecordingDeployer, 1: Deployment}
     */
    protected function deployer(?string $sha, array $meta = []): array
    {
        $deployment = Deployment::query()->create([
            'server_name' => 'test-node', 'sha' => $sha, 'branch' => 'production',
            'trigger' => Deployment::TRIGGER_POLL, 'status' => Deployment::STATUS_PENDING, 'meta' => $meta ?: null,
        ]);

        $deployer = new RecordingDeployer($deployment);
        $deployer->runner->passthrough = ['mv -T'];

        return [$deployer, $deployment];
    }
}
