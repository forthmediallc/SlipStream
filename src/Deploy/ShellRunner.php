<?php

namespace Slipstream\Deploy;

use RuntimeException;
use Slipstream\Support\HookEnvironment;
use Symfony\Component\Process\Process;

/**
 * Run external commands, streaming stdout/stderr into the event log.
 */
class ShellRunner
{
    public function __construct(
        protected EventLogger $log,
        protected ?string $defaultCwd = null
    ) {}

    /**
     * @param  list<string>|string  $command
     * @return array{0: int, 1: string}  exit code + combined output
     */
    public function run(
        array|string $command,
        ?string $cwd = null,
        ?string $step = null,
        int $timeout = 3600,
        bool $throw = true
    ): array {
        $cwd ??= $this->defaultCwd ?? getcwd() ?: null;

        if (is_string($command)) {
            $process = Process::fromShellCommandline($command, $cwd, $this->env(), null, $timeout);
            $display = $command;
        } else {
            $process = new Process($command, $cwd, $this->env(), null, $timeout);
            $display = implode(' ', $command);
        }

        $this->log->info('$ '.$display, $step);

        $buffer = '';
        $process->run(function (string $type, string $chunk) use (&$buffer, $step) {
            $buffer .= $chunk;
            foreach (preg_split("/\r\n|\n|\r/", $chunk) as $line) {
                if ($line === '') {
                    continue;
                }
                $level = $type === Process::ERR ? 'warning' : 'info';
                $this->log->line($line, $level, $step);
            }
        });

        $code = $process->getExitCode() ?? 1;

        if ($throw && $code !== 0) {
            throw new RuntimeException("Command failed (exit {$code}): {$display}".$this->outputTail($buffer));
        }

        return [$code, $buffer];
    }

    /**
     * The last few non-empty output lines, formatted for an exception message.
     *
     * The message becomes the deployment's failure_reason, so without this the
     * only thing that survives a failed step is its exit code.
     */
    protected function outputTail(string $buffer, int $lines = 5, int $maxTotal = 2000): string
    {
        // A deploy buffer is routinely megabytes (composer, npm build). Splitting
        // all of it and reversing the array to read five lines allocates the
        // whole thing twice over; the last few lines cannot be further back than
        // the trailing slice, so only that is worth parsing.
        $buffer = substr($buffer, -($lines + 1) * 4096);

        $tail = [];

        foreach (array_reverse(preg_split("/\r\n|\n|\r/", $buffer) ?: []) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $tail[] = mb_strimwidth($line, 0, 400, '…');

            if (count($tail) >= $lines) {
                break;
            }
        }

        if ($tail === []) {
            return '';
        }

        $out = "\n  │ ".implode("\n  │ ", array_reverse($tail));

        return mb_strlen($out) > $maxTotal ? mb_substr($out, 0, $maxTotal).'…' : $out;
    }

    /**
     * What the command sees: this process's environment, or the minimal one
     * (SLIPSTREAM_HOOK_ENV; minimal by default in artifact mode), with the
     * variables it leaves out set to false so Process does not add them back.
     *
     * @return array<string, string|false>
     */
    protected function env(): array
    {
        return HookEnvironment::forHook();
    }
}
