<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Slipstream\Release\ArtifactStore;
use Slipstream\Release\Core\SodiumSigner;
use Slipstream\Release\DeployRef;
use Slipstream\Release\Publisher;
use Slipstream\Tests\Support\ArtifactFixture;
use Slipstream\Tests\TestCase;

/**
 * slipstream:artifact publish and rollback: what ends up in the store, in
 * what order, and what the pointer says.
 */
class ArtifactPublishTest extends TestCase
{
    protected ArtifactFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = (new ArtifactFixture)->configure()->writeCheckout()->package();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();

        parent::tearDown();
    }

    public function test_publish_uploads_the_release_before_the_pointer_and_chains_previous(): void
    {
        $first = $this->fixture->publish('alice');

        $disk = Storage::disk('artifacts');
        $sha = $this->fixture->sha;
        foreach (['release.tar.gz', 'manifest.json', 'manifest.sig'] as $file) {
            $this->assertTrue($disk->exists("app/releases/{$sha}/{$file}"), $file);
        }
        $this->assertTrue($disk->exists('app/refs/heads/production.json'));
        $this->assertSame([$sha, null, 'alice', 'production', 'test-key'], [$first->sha, $first->previous, $first->by, $first->environment, $first->keyId]);
        $this->assertGreaterThanOrEqual(time() - 60, $first->sequence);

        // The statement signs the manifest as stored, and the archive.
        $statement = $this->fixture->statement();
        $this->assertSame(hash('sha256', $this->fixture->stored('manifest.json')), $statement->manifestSha256);
        $this->assertSame(hash_file('sha256', $this->fixture->dist.'/release.tar.gz'), $statement->archiveSha256);
        $this->assertSame($first->manifestSha256, $statement->manifestSha256);

        // A second release: the pointer moves on and remembers the first.
        $this->fixture->writeCheckout(['app/Hello.php' => '<?php class Hello { public int $version = 2; }']);
        $second = $this->fixture->package(str_repeat('b', 40))->publish('bob');

        $this->assertSame(str_repeat('b', 40), $second->sha);
        $this->assertSame(['sha' => $sha, 'manifest_sha256' => $first->manifestSha256], $second->previous);
        $this->assertGreaterThan($first->sequence, $second->sequence);
    }

    public function test_rollback_repoints_without_uploading_and_clears_previous(): void
    {
        $first = $this->fixture->publish();
        $this->fixture->writeCheckout(['app/Hello.php' => '<?php // v2']);
        $second = $this->fixture->package(str_repeat('b', 40))->publish();
        $objects = count(Storage::disk('artifacts')->allFiles());

        $back = $this->fixture->rollbackTo(substr($first->sha, 0, 8), 'ops');

        $this->assertSame($first->sha, $back->sha);
        $this->assertNull($back->previous, 'nothing walks back onto what was rolled away from');
        $this->assertGreaterThan($second->sequence, $back->sequence);
        $this->assertSame($first->manifestSha256, $back->manifestSha256);
        $this->assertSame($objects, count(Storage::disk('artifacts')->allFiles()), 'nothing uploaded');
    }

    public function test_publish_regenerates_the_manifest_from_the_archive(): void
    {
        // The build job's manifest claims a file the archive does not carry.
        $manifest = json_decode((string) file_get_contents($this->fixture->dist.'/manifest.json'), true);
        $manifest['files']['app/Backdoor.php'] = [str_repeat('e', 64), 7];
        file_put_contents($this->fixture->dist.'/manifest.json', json_encode($manifest));

        $this->fixture->publish();

        $stored = json_decode($this->fixture->stored('manifest.json'), true);
        $this->assertArrayNotHasKey('app/Backdoor.php', $stored['files']);
        $this->assertArrayHasKey('app/Hello.php', $stored['files']);
    }

    public function test_publish_refuses_a_build_for_another_ref_and_a_tag_pattern(): void
    {
        $publisher = new Publisher(new ArtifactStore);

        try {
            $publisher->publish($this->fixture->dist, DeployRef::parse('refs/heads/staging'), $this->fixture->sha, $this->fixture->signer, 'ci', 'production', fn () => null);
            $this->fail('published a production build to staging');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('The build says it is for refs/heads/production; this publish is for refs/heads/staging', $e->getMessage());
        }

        try {
            $publisher->publish($this->fixture->dist, DeployRef::parse('v*'), $this->fixture->sha, $this->fixture->signer, 'ci', 'production', fn () => null);
            $this->fail('published to a tag pattern');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Tag patterns are unsupported', $e->getMessage());
        }
    }

    public function test_rollback_refuses_a_release_that_does_not_verify(): void
    {
        $this->fixture->publish();
        $this->fixture->resignManifest($this->fixture->otherSigner());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not verify');

        $this->fixture->rollbackTo($this->fixture->sha);
    }

    public function test_the_command_signs_publishes_rolls_back_and_lists(): void
    {
        $secret = 'sodium:'.base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $signer = SodiumSigner::fromSpec($secret, 'cli-key');
        config()->set('slipstream.artifacts.public_keys', 'test-key:'.$this->fixture->publicKey.',cli-key:'.base64_encode($signer->publicKey()));

        $this->artisan('slipstream:artifact', ['action' => 'sign', 'dir' => $this->fixture->dist, '--key' => $secret, '--key-id' => 'cli-key', '--show-public-key' => true])
            ->expectsOutputToContain('SLIPSTREAM_RELEASE_PUBLIC_KEYS=cli-key:'.base64_encode($signer->publicKey()))
            ->assertExitCode(0);

        // The ref and the commit come from the publish job, never the build.
        $this->artisan('slipstream:artifact', ['action' => 'publish', 'dir' => $this->fixture->dist, '--sha' => $this->fixture->sha, '--key' => $secret, '--key-id' => 'cli-key', '--by' => 'ci'])
            ->expectsOutputToContain('Name the ref being published')
            ->assertExitCode(1);

        $this->artisan('slipstream:artifact', ['action' => 'publish', 'dir' => $this->fixture->dist, '--ref' => 'refs/heads/production', '--key' => $secret, '--key-id' => 'cli-key', '--by' => 'ci'])
            ->expectsOutputToContain('Name the commit being published')
            ->assertExitCode(1);

        $this->artisan('slipstream:artifact', ['action' => 'sign', 'dir' => $this->fixture->dist, '--ref' => 'refs/heads/staging', '--sha' => $this->fixture->sha, '--key' => $secret, '--key-id' => 'cli-key'])
            ->expectsOutputToContain('A build does not get to name its own ref')
            ->assertExitCode(1);

        $this->artisan('slipstream:artifact', ['action' => 'publish', 'dir' => $this->fixture->dist, '--ref' => 'refs/heads/production', '--sha' => $this->fixture->sha, '--key' => $secret, '--key-id' => 'cli-key', '--by' => 'ci'])
            ->expectsOutputToContain('Published: refs/heads/production → aaaaaaa.')
            ->assertExitCode(0);

        $this->artisan('slipstream:artifact', ['action' => 'list'])
            ->expectsOutputToContain('aaaaaaa · sequence')
            ->assertExitCode(0);

        $this->artisan('slipstream:artifact', ['action' => 'publish', '--rollback' => true, '--sha' => $this->fixture->sha, '--ref' => 'refs/heads/production', '--key' => $secret, '--key-id' => 'cli-key'])
            ->expectsOutputToContain('Rolled back: refs/heads/production → aaaaaaa.')
            ->assertExitCode(0);

        $this->artisan('slipstream:artifact', ['action' => 'publish', 'dir' => $this->fixture->dist])
            ->expectsOutputToContain('No signing key')
            ->assertExitCode(1);
    }

    public function test_the_publish_job_names_the_commit_and_a_build_that_claims_another_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The build says it is aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa; this publish is for bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        (new Publisher(new ArtifactStore))->publish($this->fixture->dist, DeployRef::parse('refs/heads/production'), str_repeat('b', 40), $this->fixture->signer, 'ci', 'production', fn () => null);
    }

    public function test_the_paths_a_node_may_write_come_from_publish_not_the_build(): void
    {
        // The build job's manifest asks for app/ to go unchecked.
        $built = json_decode((string) file_get_contents($this->fixture->dist.'/manifest.json'), true);
        $built['generated'] = ['app', 'vendor'];
        file_put_contents($this->fixture->dist.'/manifest.json', json_encode($built));

        $this->fixture->publish();
        $this->assertSame(['bootstrap/cache'], json_decode($this->fixture->stored('manifest.json'), true)['generated']);

        $this->fixture->writeCheckout()->package(str_repeat('b', 40));
        (new Publisher(new ArtifactStore))->publish($this->fixture->dist, DeployRef::parse('refs/heads/production'), str_repeat('b', 40), $this->fixture->signer, 'ci', 'production', fn () => null, ['generated' => ['public/build']]);
        $this->assertSame(['public/build'], json_decode($this->fixture->stored('manifest.json'), true)['generated']);
    }

    public function test_a_published_release_is_never_replaced(): void
    {
        $this->fixture->publish();
        $sequence = $this->fixture->pointer->sequence;

        // A re-run of the publish job: the same bytes, skipped, and the pointer still rises.
        $lines = [];
        $again = (new Publisher(new ArtifactStore))->publish($this->fixture->dist, DeployRef::parse('refs/heads/production'), $this->fixture->sha, $this->fixture->signer, 'ci', 'production', function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        $this->assertContains('Already in the store: release.tar.gz', $lines);
        $this->assertGreaterThan($sequence, $again->sequence);

        // Different bytes for the same commit: refused before anything uploads.
        $archive = $this->fixture->stored('release.tar.gz');
        $this->fixture->writeCheckout(['app/Hello.php' => '<?php // rebuilt'])->package();
        try {
            $this->fixture->publish();
            $this->fail('replaced a published release');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is already in the store with a different release.tar.gz', $e->getMessage());
        }
        $this->assertSame($archive, $this->fixture->stored('release.tar.gz'));
    }

    public function test_publish_refuses_an_archive_carrying_what_must_never_ship(): void
    {
        foreach ([
            [['app/Hello.php' => '<?php', 'app/.env' => 'APP_KEY=base64:secret'], 'app/.env must never ship'],
            [['app/Hello.php' => '<?php', 'config/jwt.key' => "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n"], 'config/jwt.key holds a private key'],
            [['app/Hello.php' => '<?php', 'packages/x/node_modules/a.js' => 'x'], 'node_modules/a.js must never ship'],
            [['app/Hello.php' => '<?php', 'APP/.ENV' => 'APP_KEY=x'], 'APP/.ENV must never ship'],
            // What the node writes itself: shipped, it is overwritten and then
            // reported as modified forever.
            [['app/Hello.php' => '<?php', 'bootstrap/cache/config.php' => '<?php return [];'], 'bootstrap/cache/config.php is written by the node itself'],
            [['app/Hello.php' => '<?php', 'RELEASE_SHA' => 'x'], 'RELEASE_SHA is written by the node itself'],
            [['app/Hello.php' => '<?php', '.slipstream/manifest.json' => '{}'], '.slipstream/manifest.json is written by the node itself'],
        ] as [$files, $reason]) {
            $this->buildArchive($files);

            try {
                $this->fixture->publish();
                $this->fail("published an archive that must be refused: {$reason}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        }

        $this->assertSame([], Storage::disk('artifacts')->allFiles(), 'nothing reached the store');

        // A public certificate ships; an allowed key path does too.
        $this->buildArchive(['app/Hello.php' => '<?php', 'resources/certs/rds.pem' => "-----BEGIN CERTIFICATE-----\nMIIC\n-----END CERTIFICATE-----\n", 'config/jwt.key' => "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n"]);
        (new Publisher(new ArtifactStore))->publish($this->fixture->dist, DeployRef::parse('refs/heads/production'), $this->fixture->sha, $this->fixture->signer, 'ci', 'production', fn () => null, ['allow' => ['config/jwt.key']]);
        $this->assertTrue(Storage::disk('artifacts')->exists('app/refs/heads/production.json'));
    }

    public function test_publish_refuses_what_nodes_would_refuse_before_anything_uploads(): void
    {
        // Signed with a key the nodes do not hold.
        try {
            (new Publisher(new ArtifactStore))->publish($this->fixture->dist, DeployRef::parse('refs/heads/production'), $this->fixture->sha, $this->fixture->signer, 'ci', 'production', fn () => null, [
                'keys' => \Slipstream\Release\Core\Keys::parse('node-key:'.base64_encode($this->fixture->otherSigner()->publicKey())),
            ]);
            $this->fail('published what the nodes cannot verify');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nodes would refuse the statement', $e->getMessage());
        }

        // Two files that differ only in case: every node would refuse the
        // manifest (on a case-insensitive disk the unpack refuses first).
        $this->buildArchive(['app/Hello.php' => '<?php', 'app/hello.php' => '<?php']);
        try {
            $this->fixture->publish();
            $this->fail('published a case collision');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('#differ only in case|lists app/hello\.php twice#', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_the_pointer_is_not_written_over_one_another_publish_moved(): void
    {
        $this->fixture->publish();
        $store = new ArtifactStore;
        $ref = DeployRef::parse('refs/heads/production');
        $now = $store->readPointer($ref);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Another publish moved app/refs/heads/production.json');

        $store->replacePointer($ref, $now.' (what this publish read earlier)', '{"replacement":true}');
    }

    public function test_keygen_prints_a_usable_pair(): void
    {
        $this->artisan('slipstream:artifact', ['action' => 'keygen', '--key-id' => 'prod-2026'])
            ->expectsOutputToContain('SLIPSTREAM_RELEASE_PUBLIC_KEYS=prod-2026:')
            ->assertExitCode(0);
    }

    /**
     * What a compromised build job could hand the publish job: an archive
     * written straight with TarWriter, past the packager's rules.
     *
     * @param  array<string, string>  $files
     */
    protected function buildArchive(array $files): void
    {
        $tree = $this->fixture->root.'/crafted-'.bin2hex(random_bytes(3));
        foreach ($files as $path => $contents) {
            @mkdir(dirname($tree.'/'.$path), 0777, true);
            file_put_contents($tree.'/'.$path, $contents);
        }
        $paths = array_keys($files);
        sort($paths, SORT_STRING);
        @unlink($this->fixture->dist.'/release.tar.gz');
        \Slipstream\Release\Core\TarWriter::create($this->fixture->dist.'/release.tar.gz', $tree, $paths);
    }
}
