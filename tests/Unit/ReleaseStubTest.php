<?php

namespace Slipstream\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Slipstream\Release\ArtifactSource;
use Slipstream\Release\DeployRef;
use Slipstream\Tests\Support\ArtifactFixture;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * stubs/slipstream-release.php: the generated single-file tool must be the
 * Core classes as committed, and must verify, refuse, unpack, publish and
 * roll back exactly as the node does.
 */
class ReleaseStubTest extends TestCase
{
    protected ArtifactFixture $fixture;

    protected string $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = dirname(__DIR__, 2).'/stubs/slipstream-release.php';
        $this->fixture = (new ArtifactFixture)->configure()->writeCheckout()->package();
        $this->fixture->publish();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();

        parent::tearDown();
    }

    public function test_the_committed_tool_is_what_the_build_produces(): void
    {
        require_once dirname(__DIR__, 2).'/stubs/src/build-release-tool.php';

        $this->assertSame(
            str_replace("\r\n", "\n", (string) file_get_contents($this->tool)),
            slipstream_release_tool_source(),
            'stubs/slipstream-release.php is out of date: run composer stub:build'
        );
    }

    public function test_it_verifies_a_pointer_and_refuses_a_tampered_one(): void
    {
        $pointer = $this->fixture->root.'/pointer.json';
        copy($this->fixture->store.'/app/refs/heads/production.json', $pointer);

        $process = $this->tool('verify-pointer', ['--ref' => 'refs/heads/production', '--environment' => 'production', '--keys' => $this->keys(), '--pointer' => $pointer]);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('sha='.$this->fixture->sha."\n", $process->getOutput());
        $this->assertStringContainsString('sequence='.$this->fixture->pointer->sequence."\n", $process->getOutput());

        $process = $this->tool('verify-pointer', ['--ref' => 'refs/heads/staging', '--environment' => 'production', '--keys' => $this->keys(), '--pointer' => $pointer]);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('refused (pointer): it names refs/heads/production, not refs/heads/staging', $process->getErrorOutput());

        $this->fixture->resignPointer($this->fixture->otherSigner());
        copy($this->fixture->store.'/app/refs/heads/production.json', $pointer);
        $process = $this->tool('verify-pointer', ['--ref' => 'refs/heads/production', '--environment' => 'production', '--keys' => $this->keys(), '--pointer' => $pointer]);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('signature does not verify', $process->getErrorOutput());
    }

    public function test_it_verifies_and_unpacks_a_release_and_refuses_a_tampered_archive(): void
    {
        $release = $this->fixture->store.'/app/releases/'.$this->fixture->sha;
        $into = $this->fixture->root.'/unpacked';
        $arguments = [
            '--sha' => $this->fixture->sha, '--environment' => 'production', '--keys' => $this->keys(),
            '--manifest' => $release.'/manifest.json', '--signature' => $release.'/manifest.sig',
            '--archive' => $release.'/release.tar.gz', '--extract-to' => $into,
            '--manifest-sha256' => $this->fixture->pointer->manifestSha256,
        ];

        $process = $this->tool('verify-release', $arguments);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('files=', $process->getOutput());
        $this->assertSame($this->fixture->sha, trim((string) file_get_contents($into.'/RELEASE_SHA')));
        $this->assertFileExists($into.'/.slipstream/manifest.sig');
        $this->assertFileExists($into.'/vendor/slipstream/slipstream/composer.json');

        $this->fixture->tamperArchive();
        $process = $this->tool('verify-release', ['--extract-to' => $this->fixture->root.'/unpacked-2'] + $arguments);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('refused (archive): the archive does not match the signed manifest', $process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->fixture->root.'/unpacked-2/app');
    }

    public function test_it_publishes_and_rolls_back_through_a_file_store_as_the_node_reads_it(): void
    {
        $store = $this->fixture->root.'/ci-store';
        mkdir($store);
        $secret = 'sodium:'.base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $signer = \Slipstream\Release\Core\SodiumSigner::fromSpec($secret, 'ci-key');
        $common = ['--ref' => 'refs/heads/production', '--environment' => 'production', '--key' => $secret, '--key-id' => 'ci-key', '--store' => 'file://'.$store, '--by' => 'actions'];

        $process = $this->tool('publish', $common + ['--archive' => $this->fixture->dist.'/release.tar.gz', '--manifest' => $this->fixture->dist.'/manifest.json']);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('refs/heads/production → aaaaaaa (sequence', $process->getOutput());
        $this->assertFileExists($store.'/releases/'.$this->fixture->sha.'/release.tar.gz');
        $this->assertFileExists($store.'/refs/heads/production.json');

        // The node side reads what the tool wrote.
        config()->set('filesystems.disks.ci', ['driver' => 'local', 'root' => $store]);
        config()->set('slipstream.artifacts.disk', 'ci');
        config()->set('slipstream.artifacts.prefix', '');
        config()->set('slipstream.artifacts.public_keys', 'ci-key:'.base64_encode($signer->publicKey()));
        $pointer = app(ArtifactSource::class)->pointer(DeployRef::parse('refs/heads/production'));
        $this->assertSame([$this->fixture->sha, 'actions', 'ci-key', null], [$pointer->sha, $pointer->by, $pointer->keyId, $pointer->previous]);
        $this->assertTrue(Storage::disk('ci')->exists('releases/'.$this->fixture->sha.'/manifest.sig'));

        // A second release, then a rollback to the first.
        $first = $this->fixture->sha;
        $this->fixture->writeCheckout(['app/Hello.php' => '<?php // v2'])->package(str_repeat('b', 40));
        $process = $this->tool('publish', $common + ['--archive' => $this->fixture->dist.'/release.tar.gz', '--manifest' => $this->fixture->dist.'/manifest.json']);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $second = app(ArtifactSource::class)->pointer(DeployRef::parse('refs/heads/production'), fresh: true);
        $this->assertSame([str_repeat('b', 40), $first], [$second->sha, $second->previous['sha']]);

        $process = $this->tool('rollback', $common + ['--sha' => $first]);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $back = app(ArtifactSource::class)->pointer(DeployRef::parse('refs/heads/production'), fresh: true);
        $this->assertSame([$first, null], [$back->sha, $back->previous]);
        $this->assertGreaterThan($second->sequence, $back->sequence);
    }

    public function test_usage_and_missing_options_exit_2(): void
    {
        $this->assertSame(2, $this->tool('frobnicate', [])->getExitCode());
        $process = $this->tool('verify-pointer', ['--ref' => 'refs/heads/production']);
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('--pointer is required', $process->getErrorOutput());
    }

    protected function keys(): string
    {
        return $this->fixture->keyId.':'.$this->fixture->publicKey;
    }

    /**
     * @param  array<string, string>  $options
     */
    protected function tool(string $command, array $options): Process
    {
        $argv = [PHP_BINARY, $this->tool, $command];
        foreach ($options as $name => $value) {
            $argv[] = $name.'='.$value;
        }

        $process = new Process($argv);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
