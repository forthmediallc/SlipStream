<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Tests\TestCase;

class ProvisionCommandTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-provision-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    protected function removeTree(string $path): void
    {
        foreach (glob($path.DIRECTORY_SEPARATOR.'*') ?: [] as $child) {
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }

    public function test_it_creates_the_layout_with_a_traversable_releases_directory(): void
    {
        $this->artisan('slipstream:provision --skip-key --skip-clone')->assertExitCode(0);

        $releases = $this->root.DIRECTORY_SEPARATOR.'releases';
        $logs = $this->root.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'logs';

        $this->assertDirectoryExists($releases);
        $this->assertDirectoryExists($logs);

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        // nginx runs as another user and must be able to traverse into a release.
        $this->assertSame(0755, fileperms($releases) & 0777);
        $this->assertSame(0750, fileperms($logs) & 0777);
    }

    public function test_it_widens_a_releases_directory_left_at_0750_by_an_earlier_provision(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        $releases = $this->root.DIRECTORY_SEPARATOR.'releases';
        mkdir($releases, 0750, true);
        chmod($releases, 0750);

        $this->artisan('slipstream:provision --skip-key --skip-clone')->assertExitCode(0);

        $this->assertSame(0755, fileperms($releases) & 0777);
    }

    public function test_it_points_the_operator_at_the_document_root_and_doctor(): void
    {
        $this->artisan('slipstream:provision --skip-key --skip-clone')
            ->expectsOutputToContain('current/public')
            ->expectsOutputToContain('slipstream:doctor')
            ->assertExitCode(0);
    }
}
