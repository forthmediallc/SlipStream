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
            throw new RuntimeException("Command failed (exit {$code}): {$display}");
        }

        return [$code, $buffer];
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
