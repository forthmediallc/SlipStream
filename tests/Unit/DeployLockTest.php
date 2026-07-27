<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Deploy\DeployLock;
use Slipstream\Tests\TestCase;

class DeployLockTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ss-lock-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->root.'/deploy/*') ?: []);
        @rmdir($this->root.'/deploy');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_second_acquire_fails_and_marks_pending_for_catch_up(): void
    {
        $a = new DeployLock($this->root);
        $b = new DeployLock($this->root);

        $this->assertTrue($a->acquire(), 'first acquire should win the lock');
        $this->assertFalse($b->acquire(), 'second acquire must fail while held');
        $this->assertTrue($b->hasPending(), 'a superseded trigger must leave a pending flag');

        $a->release();

        // Once released, a fresh acquirer wins and can clear the catch-up flag.
        $c = new DeployLock($this->root);
        $this->assertTrue($c->acquire());
        $c->clearPending();
        $this->assertFalse($c->hasPending());
        $c->release();
    }
}
