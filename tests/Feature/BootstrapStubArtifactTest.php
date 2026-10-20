<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Tests\Support\ArtifactFixture;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * stubs/slipstream-bootstrap.sh in artifact mode, against a store on disk:
 * a new instance comes up from the signed release with no git, no composer,
 * and nothing runs before the release verifies.
 */
class BootstrapStubArtifactTest extends TestCase
{
    protected ArtifactFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('the stub is a POSIX shell script.');
        }
        if ((new Process(['sh', '-c', 'exit 0']))->run() !== 0) {
            $this->markTestSkipped('needs sh on the PATH.');
        }

        $this->fixture = (new ArtifactFixture)->configure()->writeCheckout()->package();
        $this->fixture->publish();
        file_put_contents($this->fixture->appRoot.'/.env', implode("\n", [
            'APP_KEY=x',
            'SLIPSTREAM_RELEASE_SOURCE=artifact',
            'SLIPSTREAM_DEPLOY_REF=refs/heads/production',
            'SLIPSTREAM_RELEASE_ENVIRONMENT=production',
            'SLIPSTREAM_RELEASE_PUBLIC_KEYS="'.$this->fixture->keyId.':'.$this->fixture->publicKey.'"',
        ])."\n");
    }

    protected function tearDown(): void
    {
        // Unset when setUp() skipped before making it.
        if (isset($this->fixture)) {
            $this->fixture->remove();
        }

        parent::tearDown();
    }

    public function test_a_new_instance_bootstraps_from_the_artifact(): void
    {
        $process = $this->stub(['--verbose']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame($this->fixture->sha.' slipstream:bootstrap --no-interaction --verbose', trim((string) file_get_contents($this->fixture->appRoot.'/ran.txt')));
        $this->assertSame([], glob($this->fixture->appRoot.'/.bootstrap.*') ?: [], 'nothing left behind');
        $this->assertFileDoesNotExist($this->fixture->appRoot.'/composer-was-called');
        $this->assertDirectoryExists($this->fixture->appRoot.'/storage/logs', 'the shared storage skeleton was seeded');
    }

    public function test_a_tampered_pointer_stops_before_anything_runs(): void
    {
        $this->fixture->resignPointer($this->fixture->otherSigner());

        $process = $this->stub();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('signature does not verify', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->fixture->appRoot.'/ran.txt');
        $this->assertSame([], glob($this->fixture->appRoot.'/.bootstrap.*') ?: []);
    }

    public function test_a_tampered_archive_stops_before_anything_runs(): void
    {
        $this->fixture->tamperArchive();

        $process = $this->stub();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('does not match the signed manifest', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->fixture->appRoot.'/ran.txt');
    }

    public function test_a_tag_pattern_is_refused(): void
    {
        file_put_contents($this->fixture->appRoot.'/.env', str_replace('refs/heads/production', 'v*', (string) file_get_contents($this->fixture->appRoot.'/.env')));

        $process = $this->stub();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('tag patterns are unsupported in artifact mode', $process->getErrorOutput());
    }

    /**
     * @param  list<string>  $args
     */
    protected function stub(array $args = []): Process
    {
        $composer = $this->fixture->root.'/composer';
        file_put_contents($composer, "#!/bin/sh\ntouch \"\$SLIPSTREAM_APP_ROOT/composer-was-called\" 2>/dev/null; echo 'composer must not run on an artifact node' >&2; exit 1\n");
        chmod($composer, 0755);

        $process = new Process(['sh', dirname(__DIR__, 2).'/stubs/slipstream-bootstrap.sh', ...$args], $this->fixture->root, [
            'APP_ROOT' => $this->fixture->appRoot,
            'ARTIFACTS' => 'file://'.$this->fixture->store.'/app',
            'PHP' => PHP_BINARY,
            'COMPOSER' => $composer,
            'BOOTSTRAP_BRANCH' => '',
            'BOOTSTRAP_ARGS' => '',
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
