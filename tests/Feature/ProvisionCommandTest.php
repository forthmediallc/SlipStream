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

    /**
     * Provision run as one user for a pool that runs as another must say,
     * before the first deploy, that repo.git/releases/deploy need handing
     * over — that first deploy otherwise dies at git fetch.
     */
    public function test_a_pool_user_mismatch_names_the_whole_layout_a_deploy_writes(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || ! function_exists('posix_getpwnam')) {
            $this->markTestSkipped('needs POSIX users.');
        }

        // A mirror "cloned by this user" so it appears in the chown.
        mkdir($this->root.DIRECTORY_SEPARATOR.'repo.git', 0755, true);

        $me = \Slipstream\Diagnostics\FpmPool::currentUser();
        $other = null;
        foreach (['nobody', 'daemon', 'www-data', 'root'] as $candidate) {
            if ($candidate !== $me && is_array(@posix_getpwnam($candidate))) {
                $other = $candidate;
                break;
            }
        }
        if ($other === null) {
            $this->markTestSkipped('no second user account available.');
        }

        $this->artisan("slipstream:provision --skip-key --skip-clone --fpm-user={$other}")
            ->expectsOutputToContain("Dashboard and webhook deploys run as '{$other}'")
            ->expectsOutputToContain('insufficient permission for adding an object')
            ->expectsOutputToContain("sudo chown -R {$other}:{$other} ".$this->root.'/repo.git '.$this->root.'/releases '.$this->root.'/deploy')
            ->expectsOutputToContain("sudo -u {$other}")
            ->assertExitCode(0);
    }
}
