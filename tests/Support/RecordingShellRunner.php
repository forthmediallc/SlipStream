<?php

namespace Slipstream\Tests\Support;

use RuntimeException;
use Slipstream\Deploy\ShellRunner;

/**
 * Records every command a deploy step would run instead of running it.
 * Commands containing one of $failOn fail like a non-zero exit would.
 */
class RecordingShellRunner extends ShellRunner
{
    /** @var list<array{command: string, cwd: ?string, step: ?string}> */
    public array $commands = [];

    /** @var list<string> */
    public array $failOn = [];

    public function run(
        array|string $command,
        ?string $cwd = null,
        ?string $step = null,
        int $timeout = 3600,
        bool $throw = true
    ): array {
        $display = is_string($command) ? $command : implode(' ', $command);
        $this->commands[] = ['command' => $display, 'cwd' => $cwd, 'step' => $step];

        foreach ($this->failOn as $needle) {
            if (str_contains($display, $needle)) {
                if ($throw) {
                    throw new RuntimeException("Command failed (exit 1): {$display}");
                }

                return [1, ''];
            }
        }

        return [0, ''];
    }
}
