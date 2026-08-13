<?php

namespace Slipstream\Deploy;

use RuntimeException;
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
     * @return array<string, string>
     */
    protected function env(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $env[$k] = $v;
            }
        }

        $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        $env['PATH'] = $path;

        $home = getenv('HOME');
        if (! $home && function_exists('posix_getpwuid')) {
            $home = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        }
        if ($home) {
            $env['HOME'] = $home;
            $env['COMPOSER_HOME'] = $env['COMPOSER_HOME'] ?? ($home.'/.config/composer');
        }

        return $env;
    }
}
