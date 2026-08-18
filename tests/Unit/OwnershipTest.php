<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Diagnostics\FpmPool;
use Slipstream\Diagnostics\Ownership;
use Slipstream\Tests\TestCase;

class OwnershipTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if (DIRECTORY_SEPARATOR === '\\' || ! function_exists('posix_getpwnam')) {
            $this->markTestSkipped('POSIX ownership is not meaningful here.');
        }

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-own-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeTree($this->root);
        }

        parent::tearDown();
    }

    protected function requireNonRoot(): void
    {
        if (Ownership::runningAsRoot()) {
            $this->markTestSkipped('root can write anything; nothing to prove.');
        }
    }

    /**
     * A real account that is not the one running the tests, so the
     * owner/group/mode branch is exercised rather than is_writable().
     */
    protected function otherUser(): string
    {
        $me = FpmPool::currentUser();

        foreach (['nobody', 'daemon', 'www-data', 'root'] as $candidate) {
            if ($candidate !== $me && is_array(@posix_getpwnam($candidate))) {
                return $candidate;
            }
        }

        $this->markTestSkipped('no second user account available to test against.');
    }

    public function test_the_asking_user_gets_the_authoritative_answer(): void
    {
        $this->requireNonRoot();
        $me = FpmPool::currentUser();

        $dir = $this->root.DIRECTORY_SEPARATOR.'d';
        mkdir($dir, 0755);

        $this->assertTrue(Ownership::writableBy($dir, $me));

        chmod($dir, 0555);
        $this->assertFalse(Ownership::writableBy($dir, $me));
    }

    /**
     * The whole point: judging for a DIFFERENT user from the bits, which is
     * what doctor has to do when it runs as root or as the operator.
     */
    public function test_another_user_is_judged_from_owner_group_and_mode(): void
    {
        $other = $this->otherUser();

        $dir = $this->root.DIRECTORY_SEPARATOR.'d';
        mkdir($dir, 0755);

        // Owned by me, no other-write bit: the other user cannot write.
        $this->assertFalse(Ownership::writableBy($dir, $other), "{$other} must not be able to write a 0755 dir I own");

        chmod($dir, 0777);
        $this->assertTrue(Ownership::writableBy($dir, $other));
    }

    public function test_unknown_when_the_user_or_path_does_not_exist(): void
    {
        $this->assertNull(Ownership::writableBy($this->root, 'no-such-user-'.bin2hex(random_bytes(3))));
        $this->assertNull(Ownership::writableBy($this->root.'/nope', 'root'));
    }

    /**
     * The mixed-ownership case: the top of the tree is fine, one directory
     * deep inside is not — exactly what a single root-run fetch leaves in
     * repo.git/objects.
     */
    public function test_unwritable_directories_finds_the_one_bad_directory_deep_inside(): void
    {
        $other = $this->otherUser();

        $repo = $this->root.DIRECTORY_SEPARATOR.'repo.git';
        mkdir($repo.'/objects/ab', 0777, true);
        mkdir($repo.'/objects/cd', 0777, true);
        mkdir($repo.'/refs/heads', 0777, true);
        chmod($repo, 0777);
        chmod($repo.'/objects', 0777);
        chmod($repo.'/refs', 0777);
        chmod($repo.'/refs/heads', 0777);
        chmod($repo.'/objects/ab', 0777);
        // The one salted directory: nobody but its owner can write it.
        chmod($repo.'/objects/cd', 0755);
        // A read-only FILE is not a problem: git never rewrites an object.
        file_put_contents($repo.'/objects/ab/deadbeef', 'obj');
        chmod($repo.'/objects/ab/deadbeef', 0444);

        $bad = Ownership::unwritableDirectories($repo, $other);

        $this->assertSame([$repo.'/objects/cd'], $bad);
    }

    public function test_unwritable_directories_reports_the_root_first_and_respects_the_limit(): void
    {
        $other = $this->otherUser();

        $repo = $this->root.DIRECTORY_SEPARATOR.'repo.git';
        foreach (['aa', 'bb', 'cc', 'dd', 'ee', 'ff'] as $sub) {
            mkdir($repo.'/objects/'.$sub, 0755, true);
        }
        // Everything 0755 and owned by me: nothing here is writable by another user.

        $bad = Ownership::unwritableDirectories($repo, $other, 3);

        $this->assertCount(3, $bad);
        $this->assertSame($repo, $bad[0], 'the top of the tree is reported first');
    }

    public function test_a_healthy_tree_reports_nothing(): void
    {
        $this->requireNonRoot();
        $me = FpmPool::currentUser();

        $repo = $this->root.DIRECTORY_SEPARATOR.'repo.git';
        mkdir($repo.'/objects/ab', 0755, true);

        $this->assertSame([], Ownership::unwritableDirectories($repo, $me));
    }
}
