<?php

namespace Slipstream\Release;

use Slipstream\Fleet\SharedStore;
use Slipstream\Settings\SettingsRepository;

/**
 * The highest pointer sequence a node has accepted for a ref, so an older
 * pointer put back in the store is refused. Kept twice: in the node's own
 * Slipstream database, and in the shared store so a fresh node with an empty
 * database inherits the fleet's mark when the store still has it. Both are
 * best-effort signals; the bucket policy and Object Lock are the control.
 */
class ArtifactSequence
{
    public function __construct(protected SettingsRepository $settings) {}

    public function highWater(DeployRef $ref): int
    {
        $local = 0;
        try {
            $local = (int) ($this->settings->get($this->key($ref)) ?? 0);
        } catch (\Throwable) {
            // No settings table yet (CI, a node mid-bootstrap).
        }

        $shared = 0;
        try {
            $shared = (int) SharedStore::cache()->get($this->sharedKey($ref), 0);
        } catch (\Throwable) {
            // A cache outage must never block a deploy.
        }

        return max($local, $shared);
    }

    public function advance(DeployRef $ref, int $sequence): void
    {
        try {
            if ($sequence > (int) ($this->settings->get($this->key($ref)) ?? 0)) {
                $this->settings->set($this->key($ref), (string) $sequence);
            }
        } catch (\Throwable) {
            // See highWater().
        }

        try {
            $cache = SharedStore::cache();
            if ($sequence > (int) $cache->get($this->sharedKey($ref), 0)) {
                $cache->put($this->sharedKey($ref), $sequence, now()->addDays(365));
            }
        } catch (\Throwable) {
            // See highWater().
        }
    }

    protected function key(DeployRef $ref): string
    {
        return 'artifact_sequence:'.$ref->key();
    }

    protected function sharedKey(DeployRef $ref): string
    {
        return 'slipstream:artifact-seq:'.sha1($ref->key().'|'.SharedStore::scope());
    }
}
