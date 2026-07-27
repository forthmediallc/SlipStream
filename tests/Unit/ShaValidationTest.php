<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Deploy\Deployer;
use Slipstream\Tests\TestCase;

class ShaValidationTest extends TestCase
{
    public function test_accepts_real_hex_object_ids(): void
    {
        $this->assertTrue(Deployer::isValidSha('a1b2c3d'));
        $this->assertTrue(Deployer::isValidSha('0123456789abcdef0123456789abcdef01234567'));
    }

    public function test_rejects_path_traversal_and_junk(): void
    {
        $this->assertFalse(Deployer::isValidSha('../../etc/passwd'));
        $this->assertFalse(Deployer::isValidSha('..'));
        $this->assertFalse(Deployer::isValidSha('a1b2; rm -rf /'));
        $this->assertFalse(Deployer::isValidSha('short'));   // < 7 and non-hex
        $this->assertFalse(Deployer::isValidSha(''));
        $this->assertFalse(Deployer::isValidSha(null));
    }
}
