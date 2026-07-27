<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Models\Pipeline;
use Slipstream\Tests\TestCase;

class PipelineMatchTest extends TestCase
{
    public function test_exact_branch_and_wildcards(): void
    {
        $main = new Pipeline(['branch_pattern' => 'main']);
        $this->assertTrue($main->matchesRef('refs/heads/main'));
        $this->assertFalse($main->matchesRef('refs/heads/develop'));

        $release = new Pipeline(['branch_pattern' => 'release/*']);
        $this->assertTrue($release->matchesRef('refs/heads/release/2025.1'));
        $this->assertFalse($release->matchesRef('refs/heads/main'));

        $any = new Pipeline(['branch_pattern' => '*']);
        $this->assertTrue($any->matchesRef('refs/heads/anything'));
    }

    public function test_applies_to_server_scoping(): void
    {
        $all = new Pipeline(['server_names' => null]);
        $this->assertTrue($all->appliesToServer('web-1'));

        $scoped = new Pipeline(['server_names' => ['web-1', 'web-2']]);
        $this->assertTrue($scoped->appliesToServer('web-1'));
        $this->assertFalse($scoped->appliesToServer('web-9'));
    }
}
