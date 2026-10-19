<?php

namespace Slipstream\Fleet;

use Slipstream\Release\DeployRef;
use Slipstream\Slipstream;

/**
 * The last commit any node deployed successfully from the deploy ref, in the
 * shared store. Bootstrap falls back to it when the ref's own commit fails,
 * so a broken release does not stop an Auto Scaling group from scaling out.
 */
class LastGood
{
    public function record(DeployRef $ref, string $sha, string $name): void
    {
        try {
            SharedStore::cache()->put($this->key($ref), [
                'sha' => strtolower($sha),
                'name' => $name,
                'node' => Slipstream::serverName(),
                'at' => now()->toIso8601String(),
            ], now()->addDays(90));
        } catch (\Throwable) {
            // A store outage must not fail a deploy that already succeeded.
        }
    }

    /**
     * @return array{sha: string, name: string, node: string, at: string}|null
     */
    public function get(DeployRef $ref): ?array
    {
        try {
            $value = SharedStore::cache()->get($this->key($ref));
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) && is_string($value['sha'] ?? null) ? $value : null;
    }

    protected function key(DeployRef $ref): string
    {
        return 'slipstream:last-good:'.sha1($ref->key().'|'.SharedStore::scope());
    }
}
