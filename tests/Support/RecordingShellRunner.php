<?php

namespace Slipstream\Tests\Support;

use Closure;
use RuntimeException;
use Slipstream\Deploy\ShellRunner;

/**
 * Records every command a deploy step would run instead of running it.
 * Commands containing one of $failOn fail like a non-zero exit would, and
 * $during runs while a command "runs" (to move the clock, say).
 */
class RecordingShellRunner extends ShellRunner
{
    /** @var list<array{command: string, cwd: ?string, step: ?string, timeout: int}> */
    public array $commands = [];

    /** @var list<string> */
    public array $failOn = [];

    /** @var (Closure(string): void)|null */
    public ?Closure $during = null;

    /**
     * Commands containing one of these really run (the symlink swap, so a
     * deploy test ends with a live release).
     *
     * @var list<string>
     */
    public array $passthrough = [];

    public function run(
        array|string $command,
        ?string $cwd = null,
        ?string $step = null,
        int $timeout = 3600,
        bool $throw = true
    ): array {
        $display = is_string($command) ? $command : implode(' ', $command);
        $this->commands[] = ['command' => $display, 'cwd' => $cwd, 'step' => $step, 'timeout' => $timeout];

        foreach ($this->passthrough as $needle) {
            if (str_contains($display, $needle)) {
                return parent::run($command, $cwd, $step, $timeout, $throw);
            }
        }

        if ($this->during !== null) {
            ($this->during)($display);
        }

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
