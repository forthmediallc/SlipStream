<?php

namespace Slipstream\Models;

class DeploymentHook extends SlipstreamModel
{
    protected $table = 'slipstream_deployment_hooks';

    protected $fillable = [
        'step',
        'position',
        'name',
        'script',
        'fleet_lock',
        'enabled',
        'server_names',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'fleet_lock' => 'boolean',
            'server_names' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function appliesToServer(string $serverName): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($this->server_names === null || $this->server_names === []) {
            return true;
        }

        return in_array($serverName, $this->server_names, true);
    }
}
