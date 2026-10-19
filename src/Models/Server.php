<?php

namespace Slipstream\Models;

class Server extends SlipstreamModel
{
    protected $table = 'slipstream_servers';

    protected $fillable = [
        'server_name',
        'hostname',
        'base_url',
        'current_sha',
        'php_version',
        'disk_free_bytes',
        'fpm_service',
        'horizon_program',
        'reverb_program',
        'run_migrations',
        'enabled',
        'paused',
        'horizon_running',
        'reverb_running',
        'last_heartbeat_at',
        'poll_checked_at',
        'poll_next_at',
        'poll_result',
        'poll_detail',
        'poll_seen_sha',
        'poll_seen_ref',
        'pinned_sha',
        'pinned_reason',
        'pinned_at',
    ];

    protected function casts(): array
    {
        return [
            'run_migrations' => 'boolean',
            'enabled' => 'boolean',
            'paused' => 'boolean',
            'horizon_running' => 'boolean',
            'reverb_running' => 'boolean',
            'last_heartbeat_at' => 'datetime',
            'disk_free_bytes' => 'integer',
            'poll_checked_at' => 'datetime',
            'poll_next_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }

    public function isPinned(): bool
    {
        return (bool) $this->paused && is_string($this->pinned_sha) && $this->pinned_sha !== '';
    }

    public function isHeartbeatFresh(int $staleAfterSeconds = 120): bool
    {
        if (! $this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->gte(now()->subSeconds($staleAfterSeconds));
    }
}
