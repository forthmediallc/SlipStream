<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Deploy\HealthGate;
use Slipstream\Tests\TestCase;

class HealthGateTest extends TestCase
{
    public function test_a_missing_release_fails_the_check_instead_of_throwing(): void
    {
        $check = (new HealthGate)->check(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-no-such-release-'.bin2hex(random_bytes(4)));

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('No release at', $check['output']);
    }
}
