<?php

namespace Slipstream\Tests\Support;

use Slipstream\Deploy\ProcessDetacher;

/**
 * Records spawn calls instead of launching a real detached process, so webhook
 * and dashboard tests can assert what would have been spawned.
 */
class FakeProcessDetacher extends ProcessDetacher
{
    /** @var list<array{args: list<string>, cwd: ?string}> */
    public array $calls = [];

    public bool $throw = false;

    public function spawnArtisan(array $artisanArgs, ?string $workingDirectory = null): void
    {
        $this->calls[] = ['args' => $artisanArgs, 'cwd' => $workingDirectory];

        if ($this->throw) {
            throw new \RuntimeException('spawn failed (fake)');
        }
    }
}
