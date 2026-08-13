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

    public function test_it_adds_traverse_bits_to_a_releases_directory_left_at_0750(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        $releases = $this->root.DIRECTORY_SEPARATOR.'releases';
        mkdir($releases, 0750, true);
        chmod($releases, 0750);

        $this->artisan('slipstream:provision --skip-key --skip-clone')->assertExitCode(0);

        // Only the traverse bits are added — nothing else about the operator's
        // mode is second-guessed.
        $this->assertSame(0751, fileperms($releases) & 0777);
    }

    /**
     * Repair must only ADD the traverse bits. A setgid, group-writable
     * releases/ is the normal way to let a deploy group write and have new
     * releases inherit the group — rewriting it to a flat 0755 silently takes
     * both away.
     */
    public function test_it_preserves_setgid_and_group_write_when_repairing(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        $releases = $this->root.DIRECTORY_SEPARATOR.'releases';
        mkdir($releases, 0777, true);
        chmod($releases, 02775);

        $this->artisan('slipstream:provision --skip-key --skip-clone')->assertExitCode(0);

        $this->assertSame(02775, fileperms($releases) & 07777);
    }

    /**
     * 0711 (traversable, not listable) is a deliberate hardening that already
     * works — provision must leave it alone, and doctor agrees it is healthy.
     */
    public function test_a_hardened_0711_releases_directory_is_left_alone(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        $releases = $this->root.DIRECTORY_SEPARATOR.'releases';
        mkdir($releases, 0777, true);
        chmod($releases, 0711);

        $this->artisan('slipstream:provision --skip-key --skip-clone')->assertExitCode(0);

        $this->assertSame(0711, fileperms($releases) & 0777);
    }

    public function test_it_points_the_operator_at_the_document_root_and_doctor(): void
    {
        $this->artisan('slipstream:provision --skip-key --skip-clone')
            ->expectsOutputToContain('current/public')
            ->expectsOutputToContain('slipstream:doctor')
            ->assertExitCode(0);
    }
}
