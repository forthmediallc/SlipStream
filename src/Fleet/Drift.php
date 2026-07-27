<?php

namespace Slipstream\Fleet;

use Illuminate\Support\Collection;
use Slipstream\Models\Server;

class Drift
{
    /**
     * @return array{
     *   has_drift: bool,
     *   canonical_sha: ?string,
     *   servers: Collection<int, Server>,
     *   stale: Collection<int, Server>,
     *   offline: Collection<int, Server>
     * }
     */
    public function summary(int $staleAfterSeconds = 120): array
    {
        $servers = Server::query()->orderBy('server_name')->get();
        $cutoff = now()->subSeconds($staleAfterSeconds);

        $online = $servers->filter(
            fn (Server $s) => $s->last_heartbeat_at && $s->last_heartbeat_at->gte($cutoff)
        );

        // Canonical = most common current_sha among online, enabled servers.
        $shas = $online
            ->filter(fn (Server $s) => $s->enabled && is_string($s->current_sha) && $s->current_sha !== '')
            ->groupBy('current_sha')
            ->map->count()
            ->sortDesc();

        $canonical = $shas->keys()->first();

        $stale = $online->filter(
            fn (Server $s) => $canonical
                && $s->enabled
                && $s->current_sha
                && $s->current_sha !== $canonical
        );

        $offline = $servers->filter(
            fn (Server $s) => ! $s->last_heartbeat_at || $s->last_heartbeat_at->lt($cutoff)
        );

        return [
            'has_drift' => $stale->isNotEmpty(),
            'canonical_sha' => is_string($canonical) ? $canonical : null,
            'servers' => $servers,
            'stale' => $stale->values(),
            'offline' => $offline->values(),
        ];
    }

    public function short(?string $sha): string
    {
        return $sha ? substr($sha, 0, 7) : '—';
    }
}
