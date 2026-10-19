<?php

namespace Slipstream\Deploy;

use Slipstream\Support\PhpBinary;
use Symfony\Component\Process\Process;

/**
 * slipstream:healthcheck, run inside a release directory: the same gate a
 * deploy applies before its swap, applied again by bootstrap to what is now
 * live before the instance takes traffic.
 */
class HealthGate
{
    /**
     * @return array{ok: bool, output: string}
     */
    public function check(string $releaseDir): array
    {
        $process = new Process([PhpBinary::resolve(), 'artisan', 'slipstream:healthcheck'], $releaseDir);
        $process->setTimeout(300);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
        ];
    }
}
