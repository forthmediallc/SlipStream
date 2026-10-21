<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Fleet\Poller;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Release\ArtifactSequence;
use Slipstream\Release\ArtifactSource;
use Slipstream\Release\Core\ArtifactRefused;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\SodiumSigner;
use Slipstream\Release\DeployRef;
use Slipstream\Release\GitRefSource;
use Slipstream\Release\ReleaseRef;
use Slipstream\Release\ReleaseSource;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\Support\FakeProcessDetacher;
use Slipstream\Tests\TestCase;

/**
 * The pointer a node follows in artifact mode: signed, for this environment
 * and this ref, never older than one it already accepted.
 */
class ArtifactSourceTest extends TestCase
{
    protected const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected const OLDER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected const MANIFEST = '1111111111111111111111111111111111111111111111111111111111111111';

    protected string $root;

    protected SodiumSigner $signer;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('slipstream.trigger.mode', 'poll');
        $app['config']->set('slipstream.trigger.ref', 'refs/heads/production');
        $app['config']->set('slipstream.trigger.poll_jitter', 0);
        $app['config']->set('slipstream.release.source', 'artifact');
        $app['config']->set('slipstream.release.environment', 'production');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-artsrc-'.bin2hex(random_bytes(4));
        mkdir($this->root.DIRECTORY_SEPARATOR.'store', 0777, true);
        config()->set('slipstream.app_root', $this->root);
        config()->set('filesystems.disks.artifacts', ['driver' => 'local', 'root' => $this->root.DIRECTORY_SEPARATOR.'store']);
        config()->set('slipstream.artifacts.disk', 'artifacts');
        config()->set('slipstream.artifacts.prefix', 'app');

        $pair = SodiumSigner::generate();
        $this->signer = SodiumSigner::fromSpec('sodium:'.$pair['secret'], 'prod-2026');
        config()->set('slipstream.artifacts.public_keys', 'prod-2026:'.$pair['public']);

        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_the_provider_binds_the_artifact_source_in_artifact_mode_and_git_otherwise(): void
    {
        $this->assertInstanceOf(ArtifactSource::class, app(ReleaseSource::class));

        config()->set('slipstream.release.source', 'git');
        $this->assertInstanceOf(GitRefSource::class, app(ReleaseSource::class));
    }

    public function test_the_pointer_resolves_to_the_release_it_names_and_the_mark_is_recorded(): void
    {
        $this->publish();

        $release = app(ReleaseSource::class)->latest(DeployRef::fromConfig());

        $this->assertSame([self::SHA, 'production', false], [$release->sha, $release->name, $release->isTag]);
        // The mark is the signed pointer itself, kept locally and shared.
        $envelope = json_decode((string) Storage::disk('artifacts')->get('app/refs/heads/production.json'), true);
        $this->assertSame($envelope, app(SettingsRepository::class)->get('artifact_sequence:branch:production'), 'the node remembers what it accepted');
        $this->assertSame($envelope, json_decode((string) \Slipstream\Fleet\SharedStore::cache()->get($this->sharedKey()), true));
        $this->assertSame(['sequence' => 1000, 'manifest' => self::MANIFEST], $this->mark());
    }

    /**
     * A value written to the shared store or the node's database by hand
     * — far ahead, or just under the one-day ceiling, which would freeze the
     * fleet for a day — counts for nothing: only a pointer the key signed
     * can raise the mark.
     */
    public function test_a_mark_nobody_signed_counts_for_nothing_and_is_audited_once(): void
    {
        config()->set('slipstream.audit.enabled', true);
        Log::spy();
        app(SettingsRepository::class)->set('artifact_sequence:branch:production', (string) (time() + 86399));
        \Slipstream\Fleet\SharedStore::cache()->put($this->sharedKey(), (string) (time() + 30 * 86400), 3600);
        $this->publish(['sequence' => 1000]);

        $this->assertSame(self::SHA, app(ReleaseSource::class)->latest(DeployRef::fromConfig())->sha);
        $this->assertSame(self::SHA, app(ArtifactSource::class)->pointer(DeployRef::fromConfig(), fresh: true)->sha);

        Log::shouldHaveReceived('info')->with('slipstream.artifact.mark_ignored', \Mockery::on(fn (array $c) => $c['where'] === 'local' && str_contains($c['reason'], 'bare number')))->once();
        Log::shouldHaveReceived('info')->with('slipstream.artifact.mark_ignored', \Mockery::on(fn (array $c) => $c['where'] === 'shared'))->once();
        $this->assertSame(['sequence' => 1000, 'manifest' => self::MANIFEST], $this->mark(), 'both marks now hold the accepted pointer');
    }

    /**
     * Upgrading must not open a window for an older pointer: the bare mark
     * 0.6.0 or 0.6.1 kept in the node's own database is a floor until the
     * next accepted pointer replaces it. The shared store's copy is not (any
     * fleet member can write it).
     */
    public function test_a_mark_from_before_0_6_2_in_the_nodes_own_database_is_a_floor_until_replaced(): void
    {
        app(SettingsRepository::class)->set('artifact_sequence:branch:production', '1000:'.self::MANIFEST);
        $this->publish(['sequence' => 999, 'sha' => self::OLDER]);
        try {
            app(ReleaseSource::class)->latest(DeployRef::fromConfig());
            $this->fail('followed a pointer older than the mark 0.6.1 recorded');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('sequence 999 is below 1000', $e->reason);
        }

        $this->publish(['sequence' => 1000]);
        $this->assertSame(self::SHA, app(ReleaseSource::class)->latest(DeployRef::fromConfig())->sha);
        $this->assertIsArray(app(SettingsRepository::class)->get('artifact_sequence:branch:production'), 'replaced by the signed pointer');

        // The same bare mark in the shared store counts for nothing.
        app(SettingsRepository::class)->forget('artifact_sequence:branch:production');
        \Slipstream\Fleet\SharedStore::cache()->put($this->sharedKey(), '5000', 3600);
        $this->publish(['sequence' => 999, 'sha' => self::OLDER]);
        $this->assertSame(self::OLDER, app(ArtifactSource::class)->pointer(DeployRef::fromConfig(), fresh: true)->sha);
    }

    /**
     * A shared mark someone wiped or garbled is written again by the next
     * node that accepts the pointer, so fresh nodes keep their floor.
     */
    public function test_a_wiped_shared_mark_is_written_again_on_the_next_acceptance(): void
    {
        $this->publish(['sequence' => 1000]);
        app(ReleaseSource::class)->latest(DeployRef::fromConfig());

        \Slipstream\Fleet\SharedStore::cache()->put($this->sharedKey(), 'garbage', 3600);
        app(ArtifactSource::class)->pointer(DeployRef::fromConfig(), fresh: true); // the next minute's check

        $this->assertSame(
            json_decode((string) Storage::disk('artifacts')->get('app/refs/heads/production.json'), true),
            json_decode((string) \Slipstream\Fleet\SharedStore::cache()->get($this->sharedKey()), true),
        );
    }

    public function test_a_signed_pointer_counts_as_a_mark_only_for_this_ref_and_environment(): void
    {
        $other = Envelope::encode([
            'ref' => 'refs/heads/staging', 'sha' => self::SHA, 'manifest_sha256' => self::MANIFEST, 'sequence' => time() + 86000,
            'published_at' => now()->toIso8601String(), 'previous' => null, 'by' => 'ci', 'environment' => 'production', 'slipstream' => 'v0.6.2',
        ], Envelope::DOMAIN_POINTER, $this->signer);
        \Slipstream\Fleet\SharedStore::cache()->put($this->sharedKey(), $other, 3600);
        $forged = Envelope::encode([
            'ref' => 'refs/heads/production', 'sha' => self::SHA, 'manifest_sha256' => self::MANIFEST, 'sequence' => time() + 86000,
            'published_at' => now()->toIso8601String(), 'previous' => null, 'by' => 'ci', 'environment' => 'production', 'slipstream' => 'v0.6.2',
        ], Envelope::DOMAIN_POINTER, SodiumSigner::fromSpec('sodium:'.SodiumSigner::generate()['secret'], 'prod-2026'));
        app(SettingsRepository::class)->set('artifact_sequence:branch:production', $forged);

        $this->publish(['sequence' => 1000]);

        $this->assertSame(self::SHA, app(ReleaseSource::class)->latest(DeployRef::fromConfig())->sha, 'neither another ref\'s pointer nor one signed by another key raises the mark');
    }

    public function test_the_shared_mark_is_written_under_the_stores_lock_and_without_it_when_the_lock_is_held(): void
    {
        $this->publish(['sequence' => 1000]);
        $sequence = app(ArtifactSequence::class);
        $sequence->lockWait = 1;
        $pointer = app(ArtifactSource::class)->pointer(DeployRef::fromConfig(), accept: false);
        $envelope = (string) Storage::disk('artifacts')->get('app/refs/heads/production.json');
        $keys = app(ArtifactSource::class)->keys();

        $sequence->advance(DeployRef::fromConfig(), $pointer, $envelope, $keys, 'production');
        $held = \Slipstream\Fleet\SharedStore::cache()->lock($this->sharedKey().':lock', 10);
        $this->assertTrue($held->get(), 'advance released the lock it took');
        $this->assertSame(1000, $this->mark()['sequence']);

        // The lock now held by someone else ($held): after the wait it writes
        // anyway, which can at worst lower the mark, never freeze it.
        \Slipstream\Fleet\SharedStore::cache()->forget($this->sharedKey());
        app(SettingsRepository::class)->forget('artifact_sequence:branch:production');
        $sequence->advance(DeployRef::fromConfig(), $pointer, $envelope, $keys, 'production');
        $this->assertSame(1000, $this->mark()['sequence']);
    }

    public function test_a_refused_deploy_by_commit_does_not_back_off_the_build_the_pointer_names(): void
    {
        $this->app->instance(ProcessDetacher::class, new FakeProcessDetacher);
        // `slipstream:deploy --sha=` refused before anything said which build.
        Deployment::query()->create([
            'server_name' => 'test-node', 'sha' => self::SHA, 'branch' => 'production', 'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_FAILED, 'finished_at' => now(), 'failure_reason' => 'Refusing artifact (statement): signature does not verify',
        ]);
        $this->publish();

        $this->assertSame(Poller::DEPLOYING, app(Poller::class)->tick(force: true)['result']);
    }

    public function test_an_equal_sequence_naming_another_release_is_refused(): void
    {
        $this->publish(['sequence' => 1000]);
        app(ReleaseSource::class)->latest(DeployRef::fromConfig());

        $this->publish(['sequence' => 1000, 'sha' => self::OLDER, 'manifest_sha256' => str_repeat('2', 64)]);
        try {
            app(ReleaseSource::class)->latest(DeployRef::fromConfig());
            $this->fail('followed a second release under the same sequence');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('sequence 1000 already named another release', $e->reason);
        }
    }

    public function test_a_refusal_is_audited_once_per_change(): void
    {
        config()->set('slipstream.audit.enabled', true);
        Log::spy();
        $other = SodiumSigner::fromSpec('sodium:'.SodiumSigner::generate()['secret'], 'prod-2026');
        $read = function () {
            try {
                app(ArtifactSource::class)->pointer(DeployRef::fromConfig(), fresh: true);
            } catch (ArtifactRefused) {
                // Expected.
            }
        };

        $this->publish([], $other);
        $read();
        $read();
        $read();
        $this->publish(['environment' => 'staging']);
        $read();
        $this->publish();
        $read(); // accepted: the memory is cleared
        $this->publish([], $other);
        $read();

        Log::shouldHaveReceived('info')->with('slipstream.artifact.refused', \Mockery::on(fn (array $c) => $c['reason'] === 'signature does not verify'))->twice();
        Log::shouldHaveReceived('info')->with('slipstream.artifact.refused', \Mockery::on(fn (array $c) => str_contains($c['reason'], 'staging')))->once();
    }

    public function test_listing_the_store_does_not_advance_the_mark(): void
    {
        $this->publish(['sequence' => 1000]);

        $this->artisan('slipstream:artifact', ['action' => 'list'])->assertExitCode(0);

        $this->assertNull(app(SettingsRepository::class)->get('artifact_sequence:branch:production'));
    }

    public function test_an_older_pointer_is_refused_once_a_newer_one_was_accepted_and_the_same_one_is_not(): void
    {
        $this->publish(['sequence' => 1000]);
        app(ReleaseSource::class)->latest(DeployRef::fromConfig());

        $this->publish(['sequence' => 999, 'sha' => self::OLDER]);
        try {
            app(ReleaseSource::class)->latest(DeployRef::fromConfig());
            $this->fail('followed an older pointer');
        } catch (ArtifactRefused $e) {
            $this->assertSame('pointer', $e->stage);
            $this->assertStringContainsString('sequence 999 is below 1000', $e->getMessage());
        }

        // The same pointer, re-read every minute, is fine; so is a rollback,
        // which is a newer pointer naming an older release.
        $this->publish(['sequence' => 1000]);
        $this->assertSame(self::SHA, app(ReleaseSource::class)->latest(DeployRef::fromConfig())->sha);

        $this->publish(['sequence' => 1001, 'sha' => self::OLDER, 'previous' => null]);
        $this->assertSame(self::OLDER, app(ReleaseSource::class)->latest(DeployRef::fromConfig())->sha);
    }

    public function test_a_pointer_for_another_ref_environment_or_future_is_refused(): void
    {
        foreach ([
            [['ref' => 'refs/heads/staging'], 'names refs/heads/staging, not refs/heads/production'],
            [['environment' => 'staging'], 'signed for environment "staging"; this node is "production"'],
            [['sequence' => time() + 200000], 'more than a day in the future'],
        ] as [$overrides, $reason]) {
            $this->publish($overrides);

            try {
                app(ReleaseSource::class)->latest(DeployRef::fromConfig());
                $this->fail("followed a pointer that should be refused: {$reason}");
            } catch (ArtifactRefused $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        }
    }

    public function test_a_bad_signature_is_refused_and_audited(): void
    {
        config()->set('slipstream.audit.enabled', true);
        Log::spy();
        $other = SodiumSigner::fromSpec('sodium:'.SodiumSigner::generate()['secret'], 'prod-2026');
        $this->publish([], $other);

        try {
            app(ReleaseSource::class)->latest(DeployRef::fromConfig());
            $this->fail('followed a pointer signed by another key');
        } catch (ArtifactRefused $e) {
            $this->assertSame('signature does not verify', $e->reason);
        }

        Log::shouldHaveReceived('info')->with('slipstream.artifact.refused', \Mockery::on(
            fn (array $context) => $context['stage'] === 'pointer' && $context['reason'] === 'signature does not verify' && $context['ref'] === 'refs/heads/production'
        ))->once();
    }

    public function test_what_the_store_lacks_is_an_error_not_a_refusal(): void
    {
        try {
            app(ReleaseSource::class)->latest(DeployRef::fromConfig());
            $this->fail('resolved without a pointer');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No pointer at app/refs/heads/production.json', $e->getMessage());
        }

        config()->set('slipstream.trigger.ref', 'v*');
        try {
            app(ReleaseSource::class)->latest(DeployRef::fromConfig());
            $this->fail('resolved a tag pattern');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Tag patterns are unsupported in artifact mode', $e->getMessage());
        }
    }

    public function test_contains_vouches_for_the_head_and_its_previous_only(): void
    {
        $this->publish(['previous' => ['sha' => self::OLDER, 'manifest_sha256' => self::MANIFEST]]);
        $source = app(ReleaseSource::class);
        $head = new ReleaseRef(self::SHA, 'production', false);

        $this->assertTrue($source->contains(DeployRef::fromConfig(), $head, self::SHA));
        $this->assertTrue($source->contains(DeployRef::fromConfig(), $head, self::OLDER));
        $this->assertFalse($source->contains(DeployRef::fromConfig(), $head, str_repeat('c', 40)));
    }

    public function test_the_poller_records_a_refused_pointer_without_a_deployment_or_back_off(): void
    {
        $this->app->instance(ProcessDetacher::class, $detacher = new FakeProcessDetacher);
        $this->publish(['environment' => 'staging']);

        $tick = app(Poller::class)->tick(force: true);

        $this->assertSame(Poller::REFUSED, $tick['result']);
        $this->assertStringContainsString('signed for environment "staging"', $tick['detail']);
        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame('refused', Server::query()->sole()->poll_result);

        $this->publish();
        $this->assertSame(Poller::DEPLOYING, app(Poller::class)->tick(force: true)['result']);
        $this->assertCount(1, $detacher->calls);
        $this->assertSame(['sequence' => 1000, 'manifest_sha256' => self::MANIFEST], Deployment::query()->sole()->meta['pointer'], 'the deploy carries the pointer it was started from');
    }

    public function test_a_deploy_refused_during_the_fetch_backs_off_only_for_the_build_it_was_started_for(): void
    {
        $this->app->instance(ProcessDetacher::class, new FakeProcessDetacher);
        // Refused before meta.artifact was written: only meta.pointer says which build.
        Deployment::query()->create([
            'server_name' => 'test-node', 'sha' => self::SHA, 'branch' => 'production', 'trigger' => Deployment::TRIGGER_POLL,
            'status' => Deployment::STATUS_FAILED, 'finished_at' => now(), 'meta' => ['pointer' => ['sequence' => 1000, 'manifest_sha256' => self::MANIFEST]],
        ]);

        $this->publish(['manifest_sha256' => self::MANIFEST]);
        $this->assertSame(Poller::BACKOFF, app(Poller::class)->tick(force: true)['result']);

        $this->publish(['manifest_sha256' => str_repeat('2', 64), 'sequence' => 1001]);
        $this->assertSame(Poller::DEPLOYING, app(Poller::class)->tick(force: true)['result']);
    }

    public function test_back_off_belongs_to_the_release_that_failed_so_a_republished_build_is_tried_at_once(): void
    {
        $this->app->instance(ProcessDetacher::class, new FakeProcessDetacher);
        Deployment::query()->create([
            'server_name' => 'test-node', 'sha' => self::SHA, 'branch' => 'production', 'trigger' => Deployment::TRIGGER_POLL,
            'status' => Deployment::STATUS_FAILED, 'finished_at' => now(), 'meta' => ['artifact' => ['manifest_sha256' => self::MANIFEST]],
        ]);

        $this->publish(['manifest_sha256' => self::MANIFEST]);
        $this->assertSame(Poller::BACKOFF, app(Poller::class)->tick(force: true)['result']);

        $this->publish(['manifest_sha256' => str_repeat('2', 64), 'sequence' => 1001]);
        $this->assertSame(Poller::DEPLOYING, app(Poller::class)->tick(force: true)['result']);
    }

    /**
     * @return array{sequence: int, manifest: ?string}
     */
    protected function mark(): array
    {
        return app(ArtifactSequence::class)->highWater(DeployRef::fromConfig(), app(ArtifactSource::class)->keys(), 'production');
    }

    protected function sharedKey(): string
    {
        return 'slipstream:artifact-seq:'.sha1('branch:production|'.\Slipstream\Fleet\SharedStore::scope());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function publish(array $overrides = [], ?SodiumSigner $signer = null): void
    {
        $payload = $overrides + [
            'ref' => 'refs/heads/production',
            'sha' => self::SHA,
            'manifest_sha256' => self::MANIFEST,
            'sequence' => 1000,
            'published_at' => now()->toIso8601String(),
            'previous' => null,
            'by' => 'ci',
            'environment' => 'production',
            'slipstream' => 'v0.6.0',
        ];

        Storage::disk('artifacts')->put('app/refs/heads/production.json', Envelope::encode($payload, Envelope::DOMAIN_POINTER, $signer ?? $this->signer));
    }
}
