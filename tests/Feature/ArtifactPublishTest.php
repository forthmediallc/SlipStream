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
            $publisher->publish($this->fixture->dist, DeployRef::parse('refs/heads/staging'), $this->fixture->signer, 'ci', 'production', fn () => null);
            $this->fail('published a production build to staging');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('The build is for refs/heads/production; the pointer is refs/heads/staging', $e->getMessage());
        }

        try {
            $publisher->publish($this->fixture->dist, DeployRef::parse('v*'), $this->fixture->signer, 'ci', 'production', fn () => null);
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

        $this->artisan('slipstream:artifact', ['action' => 'publish', 'dir' => $this->fixture->dist, '--key' => $secret, '--key-id' => 'cli-key', '--by' => 'ci'])
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

    public function test_keygen_prints_a_usable_pair(): void
    {
        $this->artisan('slipstream:artifact', ['action' => 'keygen', '--key-id' => 'prod-2026'])
            ->expectsOutputToContain('SLIPSTREAM_RELEASE_PUBLIC_KEYS=prod-2026:')
            ->assertExitCode(0);
    }
}
