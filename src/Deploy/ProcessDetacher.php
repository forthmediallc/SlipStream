<?php

namespace Slipstream\Deploy;

use RuntimeException;
use Slipstream\Support\PhpBinary;

/**
 * Spawn a fully detached artisan process (setsid on Unix).
 * Same idea as deploy/deploy-trigger.sh — returns immediately so HTTP
 * (GitHub's ~10s webhook timeout) never blocks on the build.
 *
 * Detached means fire-and-forget: the shell backgrounds the child and exits 0
 * before the child has run a line, so nothing here can notice a child that
 * died on start-up. Everything that CAN be checked is checked first and thrown,
 * because every caller turns an exception from here into an honest record — a
 * failed row and a spawn_failed delivery for the webhook, a "failed to start"
 * flash for the dashboard, a revert-to-queued for a claimed fleet row.
 */
class ProcessDetacher
{
    /**
     * @param  list<string>  $artisanArgs  e.g. ['slipstream:deploy', '--deployment=1']
     *
     * @throws RuntimeException when no PHP CLI can be resolved, artisan is
     *                          missing, or the detach log cannot be written
     */
    public function spawnArtisan(array $artisanArgs, ?string $workingDirectory = null): void
    {
        // Resolved, never PHP_BINARY: in the FPM worker that serves the
        // dashboard and the webhook, PHP_BINARY is the FPM daemon, which prints
        // its usage instead of running artisan. See PhpBinary.
        $php = $this->phpBinary();
        $cwd = $workingDirectory ?: base_path();
        $artisan = $cwd.DIRECTORY_SEPARATOR.'artisan';

        if (! is_file($artisan)) {
            // When running from a release layout, artisan lives under current/
            $currentArtisan = \Slipstream\Slipstream::appRoot()
                .DIRECTORY_SEPARATOR.'current'.DIRECTORY_SEPARATOR.'artisan';
            if (is_file($currentArtisan)) {
                $artisan = $currentArtisan;
                $cwd = dirname($currentArtisan);
            } else {
                throw new RuntimeException("artisan not found at {$artisan}");
            }
        }

        $parts = array_merge([$php, $artisan], $artisanArgs);
        $commandLine = implode(' ', array_map([$this, 'escapeArg'], $parts));

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->launchWindows($commandLine);

            return;
        }

        $log = $this->createLog($artisanArgs);

        // The first line is exactly what was launched, so a log that then
        // holds only an interpreter's usage text names its own cause (the
        // wrong binary) instead of reading like an FPM misconfiguration.
        $header = sprintf("[%s] spawn in %s: %s\n", date('Y-m-d H:i:s'), $cwd, $commandLine);
        if (@file_put_contents($log, $header, FILE_APPEND) === false) {
            throw new RuntimeException("Cannot write the detach log {$log} — is deploy/logs writable by this user?");
        }

        // setsid + redirect + background: fully detach from FPM/parent.
        $cmd = sprintf(
            'setsid %s >> %s 2>&1 < /dev/null &',
            $commandLine,
            $this->escapeArg($log)
        );

        $this->launch($cmd, $cwd);
    }

    /**
     * Overridable so a test can substitute the resolver's answer (or failure).
     */
    protected function phpBinary(): string
    {
        return PhpBinary::resolve();
    }

    protected function launch(string $cmd, string $cwd): void
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $this->environment());
        if (! is_resource($proc)) {
            throw new RuntimeException('Failed to spawn detached process: '.$cmd);
        }
        proc_close($proc);
    }

    /**
     * Windows (local Herd): start detached; the production path is Linux.
     * The empty "" is the window title — without it cmd's `start` treats the
     * quoted php path as the title and never launches the process.
     */
    protected function launchWindows(string $commandLine): void
    {
        pclose(popen('start "" /B '.$commandLine.' > NUL 2>&1', 'r'));
    }

    /**
     * @param  list<string>  $artisanArgs
     */
    protected function createLog(array $artisanArgs): string
    {
        $logDir = $this->logDirectory();
        if (! is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
        $log = $logDir.'/detach-'.date('Ymd_His').$this->logSuffix($artisanArgs).'.log';

        // Create it ourselves before the shell's append redirect does, so the
        // mode is 0600 rather than whatever the umask allows — deploy output
        // routinely echoes secrets.
        if (! is_file($log)) {
            @touch($log);
            @chmod($log, 0600);
        }

        return $log;
    }

    /**
     * "-deploy-2" for ['slipstream:deploy', '--deployment=2']: the command and
     * its first option value, so a log matches its row without being opened.
     *
     * @param  list<string>  $artisanArgs
     */
    protected function logSuffix(array $artisanArgs): string
    {
        $parts = [preg_replace('/^slipstream:/', '', $artisanArgs[0] ?? '')];

        foreach (array_slice($artisanArgs, 1) as $arg) {
            if (preg_match('/^--[\w-]+=(.+)$/', $arg, $m) === 1) {
                $parts[] = $m[1];
                break;
            }
        }

        $slug = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', implode('-', array_filter($parts))), '-');

        return $slug === '' ? '' : '-'.substr($slug, 0, 60);
    }

    /**
     * What the detached artisan sees: this process's environment, in every
     * mode. It is Slipstream's own process and the one that downloads
     * releases, so it keeps its proxy and AWS settings; the hooks it runs are
     * trimmed by ShellRunner (SLIPSTREAM_HOOK_ENV).
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        return \Slipstream\Support\HookEnvironment::inherit();
    }

    protected function logDirectory(): string
    {
        return \Slipstream\Slipstream::appRoot()
            .DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'logs';
    }

    protected function escapeArg(string $arg): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return '"'.str_replace('"', '""', $arg).'"';
        }

        return escapeshellarg($arg);
    }
}
