<?php

namespace Slipstream\Release;

use Slipstream\Fleet\SharedStore;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\Pointer;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\AuditLog;

/**
 * The highest pointer a node has accepted for a ref, so an older pointer put
 * back in the store is refused, and so is another release under the same
 * sequence. The mark is that signed pointer itself, kept twice — in the
 * node's own Slipstream database, and in the shared store so a fresh node
 * inherits the fleet's — and verified every time it is read: only a pointer
 * the release key signed, for this ref and environment, can raise it. A
 * value written to either place by hand counts for nothing, so no write to
 * the shared store can stop the fleet deploying.
 *
 * The node also remembers the last refusal per ref, so a bad pointer is
 * audited once, not every minute.
 */
class ArtifactSequence
{
    /** Seconds advance() waits for the shared store's lock. */
    public int $lockWait = 3;

    public function __construct(protected SettingsRepository $settings) {}

    /**
     * @return array{sequence: int, manifest: ?string}
     */
    public function highWater(DeployRef $ref, Keys $keys, string $environment): array
    {
        $local = $this->mark('local', $ref, $keys, $environment, fn () => $this->settings->get($this->key($ref)));
        $shared = $this->mark('shared', $ref, $keys, $environment, fn () => SharedStore::cache()->get($this->sharedKey($ref)));

        return $shared['sequence'] > $local['sequence'] ? $shared : $local;
    }

    /**
     * Record an accepted pointer — its signed envelope — where it raises the
     * mark: an atomic max under the shared store's lock where it has one. A
     * write without the lock can at worst lower the mark, never freeze it.
     */
    public function advance(DeployRef $ref, Pointer $pointer, string $envelope, Keys $keys, string $environment): void
    {
        try {
            $local = $this->mark('local', $ref, $keys, $environment, fn () => $this->settings->get($this->key($ref)));
            if ($pointer->sequence > $local['sequence'] || $local['manifest'] === null) {
                $this->settings->set($this->key($ref), $envelope);
            }
        } catch (\Throwable) {
            // No settings table yet (CI, a node mid-bootstrap).
        }

        try {
            $cache = SharedStore::cache();
            $key = $this->sharedKey($ref);

            $lock = null;
            try {
                $lock = $cache->lock($key.':lock', 10);
                if (! $lock->block($this->lockWait)) {
                    $lock = null;
                }
            } catch (\Throwable) {
                // A store without locks, or a held one: write unlocked.
                $lock = null;
            }

            try {
                $shared = $this->mark('shared', $ref, $keys, $environment, fn () => $cache->get($key));
                if ($pointer->sequence > $shared['sequence'] || $shared['manifest'] === null) {
                    $cache->put($key, $envelope, now()->addDays(365));
                }
            } finally {
                $lock?->release();
            }
        } catch (\Throwable) {
            // A cache outage must never block a deploy.
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
                $this->settings->forget($this->refusalKey($ref));
            }
        } catch (\Throwable) {
            // See isNewRefusal().
        }
    }

    /**
     * A stored mark, verified: the sequence and manifest of the signed
     * pointer it holds, or nothing. Anything else is ignored and audited
     * once per value — a bare number from 0.6.0 or 0.6.1 included, so one
     * written by hand is seen.
     *
     * @param  \Closure(): mixed  $read
     * @return array{sequence: int, manifest: ?string}
     */
    protected function mark(string $where, DeployRef $ref, Keys $keys, string $environment, \Closure $read): array
    {
        $none = ['sequence' => 0, 'manifest' => null];

        try {
            $value = $read();
        } catch (\Throwable) {
            return $none;
        }
        if ($value === null || $value === '') {
            return $none;
        }
        // The settings store hands JSON back decoded; the envelope verifies
        // from its payload, so encoding it again loses nothing.
        if (is_array($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        try {
            if (is_int($value) || (is_string($value) && preg_match('/^\d+(:[0-9a-f]{64})?$/', $value) === 1)) {
                throw new \RuntimeException('it is a bare number, not a signed pointer (0.6.0 and 0.6.1 wrote such marks; the next accepted pointer replaces it)');
            }
            if (! is_string($value)) {
                throw new \RuntimeException('it is not a signed pointer');
            }
            $verified = Envelope::verify($value, Envelope::DOMAIN_POINTER, $keys);
            $pointer = Pointer::fromPayload($verified['payload'], $verified['key_id']);
            if ($pointer->ref !== $ref->full() || $pointer->environment !== $environment) {
                throw new \RuntimeException("it is a pointer for {$pointer->ref} in {$pointer->environment}");
            }
            if ($pointer->sequence > time() + Pointer::MAX_FUTURE_SECONDS) {
                throw new \RuntimeException("its sequence {$pointer->sequence} is more than a day in the future");
            }

            return ['sequence' => $pointer->sequence, 'manifest' => $pointer->manifestSha256];
        } catch (\Throwable $e) {
            $this->ignored($ref, $where, is_string($value) ? $value : json_encode($value), $e->getMessage());

            return $none;
        }
    }

    protected function ignored(DeployRef $ref, string $where, string $value, string $why): void
    {
        $context = ['ref' => $ref->full(), 'where' => $where, 'reason' => $why];

        try {
            $seen = 'artifact_mark_ignored:'.$where.':'.$ref->key();
            if ($this->settings->get($seen) === sha1($value)) {
                return;
            }
            $this->settings->set($seen, sha1($value));
        } catch (\Throwable) {
            // Without a memory, audit every time.
        }

        AuditLog::record('artifact.mark_ignored', $context);
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
