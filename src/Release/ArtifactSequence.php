<?php

namespace Slipstream\Release;

use Slipstream\Fleet\SharedStore;
use Slipstream\Release\Core\Pointer;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\AuditLog;

/**
 * The highest pointer a node has accepted for a ref — its sequence and the
 * manifest it named — so an older pointer put back in the store is refused,
 * and so is another release under the same sequence. Kept twice: in the
 * node's own Slipstream database, and in the shared store so a fresh node
 * with an empty database inherits the fleet's mark when the store still has
 * it. Both are best-effort signals; the bucket policy is the control.
 *
 * A mark further ahead than any node could have accepted (more than a day
 * in the future) is ignored and audited: one write to the shared store must
 * not stop every node deploying. The node also remembers the last refusal
 * per ref, so a bad pointer is audited once, not every minute.
 */
class ArtifactSequence
{
    public function __construct(protected SettingsRepository $settings) {}

    /**
     * @return array{sequence: int, manifest: ?string}  manifest is null for a mark written before 0.6.1
     */
    public function highWater(DeployRef $ref): array
    {
        $local = ['sequence' => 0, 'manifest' => null];
        try {
            $local = self::parse($this->settings->get($this->key($ref)));
        } catch (\Throwable) {
            // No settings table yet (CI, a node mid-bootstrap).
        }

        $shared = ['sequence' => 0, 'manifest' => null];
        try {
            $shared = self::parse(SharedStore::cache()->get($this->sharedKey($ref)));
        } catch (\Throwable) {
            // A cache outage must never block a deploy.
        }

        $local = $this->plausible($ref, 'local', $local);
        $shared = $this->plausible($ref, 'shared', $shared);

        return $shared['sequence'] > $local['sequence'] ? $shared : $local;
    }

    /**
     * Record an accepted pointer: an atomic max in the shared store (under
     * its lock, where it has one), which also replaces an implausible mark.
     */
    public function advance(DeployRef $ref, int $sequence, string $manifestSha256): void
    {
        $value = $sequence.':'.$manifestSha256;

        try {
            if (self::raises(self::parse($this->settings->get($this->key($ref))), $sequence)) {
                $this->settings->set($this->key($ref), $value);
            }
        } catch (\Throwable) {
            // See highWater().
        }

        try {
            $cache = SharedStore::cache();
            $key = $this->sharedKey($ref);

            $lock = null;
            try {
                $lock = $cache->lock($key.':lock', 10);
                if (! $lock->block(3)) {
                    $lock = null;
                }
            } catch (\Throwable) {
                // A store without locks, or a held one: write unlocked.
                $lock = null;
            }

            try {
                if (self::raises(self::parse($cache->get($key)), $sequence)) {
                    $cache->put($key, $value, now()->addDays(365));
                }
            } finally {
                $lock?->release();
            }
        } catch (\Throwable) {
            // See highWater().
        }
    }

    /**
     * Whether a refusal is news: true the first time a ref is refused for a
     * given reason, false while the same refusal repeats.
     */
    public function isNewRefusal(DeployRef $ref, string $signature): bool
    {
        try {
            if ($this->settings->get($this->refusalKey($ref)) === $signature) {
                return false;
            }
            $this->settings->set($this->refusalKey($ref), $signature);
        } catch (\Throwable) {
            // Without a memory every refusal is news.
        }

        return true;
    }

    public function forgetRefusal(DeployRef $ref): void
    {
        try {
            if ($this->settings->get($this->refusalKey($ref)) !== null) {
                $this->settings->set($this->refusalKey($ref), null);
            }
        } catch (\Throwable) {
            // See isNewRefusal().
        }
    }

    /**
     * @return array{sequence: int, manifest: ?string}
     */
    public static function parse(mixed $value): array
    {
        if (is_int($value)) {
            return ['sequence' => $value, 'manifest' => null];
        }
        if (is_string($value) && preg_match('/^(\d+)(?::([0-9a-f]{64}))?$/', trim($value), $m) === 1) {
            // Nineteen digits can exceed PHP_INT_MAX; such a mark is implausible anyway.
            $sequence = strlen(ltrim($m[1], '0')) >= 19 ? PHP_INT_MAX : (int) $m[1];

            return ['sequence' => $sequence, 'manifest' => $m[2] ?? null];
        }

        return ['sequence' => 0, 'manifest' => null];
    }

    public static function isPlausible(int $sequence): bool
    {
        return $sequence <= time() + Pointer::MAX_FUTURE_SECONDS;
    }

    /**
     * @param  array{sequence: int, manifest: ?string}  $current
     */
    protected static function raises(array $current, int $sequence): bool
    {
        return $sequence > $current['sequence']
            || ($sequence === $current['sequence'] && $current['manifest'] === null)
            || ! self::isPlausible($current['sequence']);
    }

    /**
     * @param  array{sequence: int, manifest: ?string}  $mark
     * @return array{sequence: int, manifest: ?string}
     */
    protected function plausible(DeployRef $ref, string $where, array $mark): array
    {
        if (self::isPlausible($mark['sequence'])) {
            return $mark;
        }

        try {
            $seen = 'artifact_mark_ignored:'.$where.':'.$ref->key();
            if ($this->settings->get($seen) !== (string) $mark['sequence']) {
                $this->settings->set($seen, (string) $mark['sequence']);
                AuditLog::record('artifact.mark_ignored', ['ref' => $ref->full(), 'where' => $where, 'sequence' => $mark['sequence']]);
            }
        } catch (\Throwable) {
            AuditLog::record('artifact.mark_ignored', ['ref' => $ref->full(), 'where' => $where, 'sequence' => $mark['sequence']]);
        }

        return ['sequence' => 0, 'manifest' => null];
    }

    protected function key(DeployRef $ref): string
    {
        return 'artifact_sequence:'.$ref->key();
    }

    protected function refusalKey(DeployRef $ref): string
    {
        return 'artifact_refused:'.$ref->key();
    }

    protected function sharedKey(DeployRef $ref): string
    {
        return 'slipstream:artifact-seq:'.sha1($ref->key().'|'.SharedStore::scope());
    }
}
