<?php

namespace Slipstream\Tests\Support;

use Slipstream\Deploy\HealthGate;

class FakeHealthGate extends HealthGate
{
    public bool $ok = true;

    /** @var list<string> */
    public array $checked = [];

    public function check(string $releaseDir): array
    {
        $this->checked[] = $releaseDir;

        return [
            'ok' => $this->ok,
            'output' => $this->ok ? 'All health checks passed.' : 'Database: connection refused',
        ];
    }
}
