<?php

namespace Slipstream\Deploy;

use RuntimeException;

/**
 * Spawn a fully detached artisan process (setsid on Unix).
 * Same idea as deploy/deploy-trigger.sh — returns immediately so HTTP
 * (GitHub's ~10s webhook timeout) never blocks on the build.
 */
class ProcessDetacher
{
    /**
     * @param  list<string>  $artisanArgs  e.g. ['slipstream:deploy', '--deployment=1']
     */
    public function spawnArtisan(array $artisanArgs, ?string $workingDirectory = null): void
    {
        $php = config('slipstream.deploy.php_binary') ?: PHP_BINARY;
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
            // Windows (local Herd): start detached; production path is Linux.
            // The empty "" is the window title — without it cmd's `start` treats
            // the quoted php path as the title and never launches the process.
            $cmd = 'start "" /B '.$commandLine.' > NUL 2>&1';
            pclose(popen($cmd, 'r'));

            return;
        }

        $logDir = $this->logDirectory();
        if (! is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
        $log = $logDir.'/detach-'.date('Ymd_His').'.log';

        // Create it ourselves before the shell's append redirect does, so the
        // mode is 0600 rather than whatever the umask allows — deploy output
        // routinely echoes secrets.
        if (! is_file($log)) {
            @touch($log);
            @chmod($log, 0600);
        }

        // setsid + redirect + background: fully detach from FPM/parent.
        $cmd = sprintf(
            'setsid %s >> %s 2>&1 < /dev/null &',
            $commandLine,
            $this->escapeArg($log)
        );

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
     * @return array<string, string>
     */
    protected function environment(): array
    {
        $env = $_ENV + $_SERVER;
        $home = getenv('HOME') ?: (function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['dir'] ?? null)
            : null);

        if ($home) {
            $env['HOME'] = $home;
            $env['COMPOSER_HOME'] = $env['COMPOSER_HOME'] ?? ($home.'/.config/composer');
        }

        $env['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

        // Only string values for proc_open
        return array_filter($env, fn ($v) => is_string($v));
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
