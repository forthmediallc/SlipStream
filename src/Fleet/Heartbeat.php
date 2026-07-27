<?php

namespace Slipstream\Fleet;

use Slipstream\Environment\EnvSync;
use Slipstream\Models\Server;
use Slipstream\Slipstream;

/**
 * Self-register this node in slipstream_servers (no SSH).
 */
class Heartbeat
{
    public function pulse(?string $currentSha = null): Server
    {
        $appRoot = Slipstream::appRoot();
        $sha = $currentSha ?? $this->readCurrentSha($appRoot);

        $server = Server::query()->updateOrCreate(
            ['server_name' => Slipstream::serverName()],
            [
                'hostname' => gethostname() ?: null,
                'current_sha' => $sha,
                'php_version' => PHP_VERSION,
                'disk_free_bytes' => @disk_free_space($appRoot) ?: null,
                'fpm_service' => config('slipstream.services.fpm'),
                'horizon_program' => config('slipstream.services.horizon'),
                'reverb_program' => config('slipstream.services.reverb'),
                'horizon_running' => $this->serviceStatus($appRoot, 'horizon', 'laravel/horizon'),
                'reverb_running' => $this->serviceStatus($appRoot, 'reverb', 'laravel/reverb'),
                'last_heartbeat_at' => now(),
            ]
        );

        // Keep this node's encrypted .env snapshot fresh for the dashboard.
        // Best-effort — publishSnapshot() swallows its own errors, but guard the
        // whole call so a container-resolution issue can't break a heartbeat.
        try {
            app(EnvSync::class)->publishSnapshot();
        } catch (\Throwable) {
            // Snapshot publishing must never break the heartbeat.
        }

        return $server;
    }

    /**
     * Liveness for an optional service. Null (shown as "—") when the package
     * isn't installed, so apps without Horizon/Reverb aren't reported "down".
     */
    protected function serviceStatus(string $appRoot, string $service, string $composerPackage): ?bool
    {
        $installed = is_dir($appRoot.'/current/vendor/'.$composerPackage)
            || is_dir(base_path('vendor/'.$composerPackage));

        if (! $installed) {
            return null;
        }

        return $this->processLooksAlive($service);
    }

    public function readCurrentSha(string $appRoot): ?string
    {
        $candidates = [
            $appRoot.DIRECTORY_SEPARATOR.'current'.DIRECTORY_SEPARATOR.'RELEASE_SHA',
            base_path('RELEASE_SHA'),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $sha = trim((string) file_get_contents($path));

                return $sha !== '' ? $sha : null;
            }
        }

        // Fallback: git rev-parse when running from a checkout (local/dev).
        if (is_dir(base_path('.git'))) {
            $out = @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD 2>/dev/null');
            $sha = is_string($out) ? trim($out) : '';

            return $sha !== '' ? $sha : null;
        }

        return null;
    }

    /**
     * Best-effort process check (Linux). Null/false when unknown.
     */
    protected function processLooksAlive(string $needle): ?bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return null;
        }

        $cmd = 'pgrep -f '.escapeshellarg($needle).' >/dev/null 2>&1';
        exec($cmd, $_, $code);

        return $code === 0;
    }
}
