<?php

namespace Slipstream\Models;

class DeploymentHook extends SlipstreamModel
{
    /**
     * Where the hook came from: a row in the database, or the hooks file the
     * release carries (an unsaved instance, never written back).
     */
    public string $source = 'database';

    /**
     * Where the hook runs: on the node, during the deploy (every hook until
     * 0.6.0), or at build time in CI (`where => build` in the hooks file).
     * In artifact mode a node never runs a build-time hook. Not a column:
     * stored hooks are always node hooks, since CI cannot read a database.
     */
    public string $where = 'node';

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
