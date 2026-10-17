<?php

namespace Slipstream\Console\Commands\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Slipstream;

/**
 * Rendering and option parsing shared by the commands that stand in for
 * dashboard pages, so a deployment or a server reads the same everywhere.
 */
trait FormatsConsoleOutput
{
    protected function shortSha(?string $sha): string
    {
        return is_string($sha) && $sha !== '' ? substr($sha, 0, 7) : '—';
    }

    protected function ago(?CarbonInterface $at): string
    {
        return $at ? $at->diffForHumans(null, null, true) : '—';
    }

    protected function duration(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms.'ms';
        }

        $seconds = (int) round($ms / 1000);

        return $seconds >= 60
            ? sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60)
            : $seconds.'s';
    }

    protected function truncate(?string $text, int $limit = 60): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) $text));

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }

    protected function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        return $bytes >= 1_073_741_824
            ? sprintf('%.1f GB', $bytes / 1_073_741_824)
            : sprintf('%d MB', intdiv($bytes, 1_048_576));
    }

    /**
     * A 1/0, on/off, yes/no, true/false option; null when it was not given.
     */
    protected function flag(string $option): ?bool
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InvalidArgumentException("--{$option} expects 1/0, on/off, yes/no or true/false.");
        }

        return $parsed;
    }

    /**
     * A comma-separated server-name option. "all" (or empty) means every
     * server and is stored as null, the way the dashboard stores it. Names
     * the registry has never seen are allowed — a node that has not
     * heartbeated yet is still a valid target — but called out.
     *
     * @return list<string>|null
     */
    protected function serverNames(string $option): ?array
    {
        $raw = (string) $this->option($option);
        if (trim($raw) === '' || strtolower(trim($raw)) === 'all') {
            return null;
        }

        $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
        $known = Server::query()->pluck('server_name')->all();
        $unknown = array_diff($names, $known);

        if ($unknown !== []) {
            $this->components->warn('Not in the fleet registry yet: '.implode(', ', $unknown).' — applies once a node with that name heartbeats.');
        }

        return $names;
    }

    protected function json(mixed $data): void
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string|int>
     */
    protected function deploymentRow(Deployment $deployment): array
    {
        return [
            '#'.$deployment->id,
            $deployment->server_name,
            $deployment->status,
            $deployment->trigger,
            $this->shortSha($deployment->sha),
            $deployment->branch ?: '—',
            $this->ago($deployment->started_at ?? $deployment->created_at),
            $this->duration($deployment->duration_ms),
            $this->truncate($deployment->failure_reason ?: $deployment->warnings, 50),
        ];
    }

    /** @var list<string> */
    protected array $deploymentHeaders = ['ID', 'Server', 'Status', 'Trigger', 'SHA', 'Branch', 'Started', 'Took', 'Note'];

    /**
     * @return array<string, mixed>
     */
    protected function deploymentJson(Deployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'server' => $deployment->server_name,
            'status' => $deployment->status,
            'trigger' => $deployment->trigger,
            'sha' => $deployment->sha,
            'branch' => $deployment->branch,
            'commit_message' => $deployment->commit_message,
            'commit_author' => $deployment->commit_author,
            'pipeline_id' => $deployment->pipeline_id,
            'initiator_user_id' => $deployment->initiator_user_id,
            'release_path' => $deployment->release_path,
            'failure_reason' => $deployment->failure_reason,
            'warnings' => $deployment->warnings,
            'fleet_batch' => $deployment->fleetBatch(),
            'created_at' => $deployment->created_at?->toIso8601String(),
            'started_at' => $deployment->started_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
            'duration_ms' => $deployment->duration_ms,
        ];
    }

    /**
     * One row per server, as the Servers page shows them.
     *
     * @param  Collection<int, Server>  $servers
     * @return list<list<string>>
     */
    protected function serverRows(Collection $servers, int $staleAfter): array
    {
        $cutoff = now()->subSeconds($staleAfter);
        $self = Slipstream::serverName();

        return $servers->map(fn (Server $server) => [
            $server->server_name.($server->server_name === $self ? ' (this)' : ''),
            $server->last_heartbeat_at && $server->last_heartbeat_at->gte($cutoff) ? 'online' : 'offline',
            ! $server->enabled ? 'disabled' : ($server->paused ? 'paused' : 'active'),
            $this->shortSha($server->current_sha),
            $this->ago($server->last_heartbeat_at),
            (string) ($server->php_version ?: '—'),
            $this->bytes($server->disk_free_bytes),
            $server->horizon_running === null ? '—' : ($server->horizon_running ? 'up' : 'down'),
            $server->reverb_running === null ? '—' : ($server->reverb_running ? 'up' : 'down'),
            $server->run_migrations ? 'yes' : 'no',
            (string) ($server->base_url ?: '—'),
        ])->values()->all();
    }

    /** @var list<string> */
    protected array $serverHeaders = ['Server', 'Online', 'Mode', 'Current', 'Heartbeat', 'PHP', 'Disk free', 'Horizon', 'Reverb', 'Migrates', 'Base URL'];

    /**
     * @return array<string, mixed>
     */
    protected function serverJson(Server $server, int $staleAfter): array
    {
        $cutoff = now()->subSeconds($staleAfter);

        return [
            'server' => $server->server_name,
            'this' => $server->server_name === Slipstream::serverName(),
            'online' => (bool) ($server->last_heartbeat_at && $server->last_heartbeat_at->gte($cutoff)),
            'enabled' => (bool) $server->enabled,
            'paused' => (bool) $server->paused,
            'current_sha' => $server->current_sha,
            'last_heartbeat_at' => $server->last_heartbeat_at?->toIso8601String(),
            'php_version' => $server->php_version,
            'disk_free_bytes' => $server->disk_free_bytes,
            'horizon_running' => $server->horizon_running,
            'reverb_running' => $server->reverb_running,
            'run_migrations' => (bool) $server->run_migrations,
            'base_url' => $server->base_url,
            'fpm_service' => $server->fpm_service,
            'horizon_program' => $server->horizon_program,
            'reverb_program' => $server->reverb_program,
        ];
    }

    /**
     * The drift line under a server table: who disagrees with the fleet and
     * who has gone quiet.
     *
     * @param  array{has_drift: bool, canonical_sha: ?string, stale: Collection<int, Server>, offline: Collection<int, Server>}  $summary
     */
    protected function renderDrift(array $summary): void
    {
        if ($summary['has_drift']) {
            $stale = $summary['stale']
                ->map(fn (Server $s) => $s->server_name.' ('.$this->shortSha($s->current_sha).')')
                ->implode(', ');
            $this->components->warn('Drift: fleet is on '.$this->shortSha($summary['canonical_sha']).'; behind or ahead: '.$stale.'. Redeploy a pinned SHA to converge: slipstream:deploy --fleet --sha=<sha>');
        }

        if ($summary['offline']->isNotEmpty()) {
            $this->components->warn('Offline: '.$summary['offline']->pluck('server_name')->implode(', '));
        }
    }
}
