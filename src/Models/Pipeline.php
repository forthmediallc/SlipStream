<?php

namespace Slipstream\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    protected $table = 'slipstream_pipelines';

    protected $fillable = [
        'name',
        'branch_pattern',
        'auto_deploy',
        'enabled',
        'server_names',
    ];

    protected function casts(): array
    {
        return [
            'auto_deploy' => 'boolean',
            'enabled' => 'boolean',
            'server_names' => 'array',
        ];
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'pipeline_id');
    }

    /**
     * Match a git ref (e.g. refs/heads/main) against this pipeline.
     */
    public function matchesRef(string $ref): bool
    {
        $branch = str_starts_with($ref, 'refs/heads/')
            ? substr($ref, strlen('refs/heads/'))
            : $ref;

        $pattern = $this->branch_pattern;

        if ($pattern === $branch || $pattern === '*') {
            return true;
        }

        // Simple glob: main, release/*, feature/*
        $regex = '/^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/';

        return (bool) preg_match($regex, $branch);
    }

    /**
     * Whether this pipeline targets the given server (null server_names = all).
     */
    public function appliesToServer(string $serverName): bool
    {
        if ($this->server_names === null || $this->server_names === []) {
            return true;
        }

        return in_array($serverName, $this->server_names, true);
    }
}
