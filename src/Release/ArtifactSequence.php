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
 * sequence. The mark is that signed pointer itself, kept twice — in
 * Slipstream's database (the node's own, or shared with the fleet when its
 * tables are), and in the shared store so a fresh node inherits the
 * fleet's — and verified every time it is read: only a pointer
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
        $mark = $shared['sequence'] > $local['sequence'] ? $shared : $local;

        return ['sequence' => $mark['sequence'], 'manifest' => $mark['manifest']];
    }

    /**
     * Record an accepted pointer — its signed envelope — wherever the mark is
     * lower, missing, garbled or unsigned: called on every acceptance, so a
     * shared mark someone wiped is written again within a minute and fresh
     * nodes keep their floor. The shared store's lock is taken only when
     * there is something to write; a write without it can at worst lower
     * the mark, never freeze it.
     */
    public function advance(DeployRef $ref, Pointer $pointer, string $envelope, Keys $keys, string $environment): void
    {
        try {
            $local = $this->mark('local', $ref, $keys, $environment, fn () => $this->settings->get($this->key($ref)));
            if (self::needsWrite($local, $pointer)) {
                $this->settings->set($this->key($ref), $envelope);
            }
        } catch (\Throwable) {
            // No settings table yet (CI, a node mid-bootstrap).
        }

        try {
            $cache = SharedStore::cache();
            $key = $this->sharedKey($ref);
            $read = fn () => $cache->get($key);

            if (! self::needsWrite($this->mark('shared', $ref, $keys, $environment, $read), $pointer)) {
                return;
            }

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
                if (self::needsWrite($this->mark('shared', $ref, $keys, $environment, $read), $pointer)) {
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
     * @param  array{sequence: int, manifest: ?string, signed: bool}  $mark
     */
    protected static function needsWrite(array $mark, Pointer $pointer): bool
    {
        return ! $mark['signed'] || $pointer->sequence > $mark['sequence'];
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
     * once per value — with one exception: a bare number 0.6.0 or 0.6.1
     * recorded in Slipstream's database, already in the past, is a floor
     * until the next accepted pointer replaces it, so upgrading does not open
     * a window for an older pointer. (The shared store's copy is not: anyone
     * who can write the cache can put a number there, and a cache write never
     * reaches the database.)
     *
     * @param  \Closure(): mixed  $read
     * @return array{sequence: int, manifest: ?string, signed: bool}
     */
    protected function mark(string $where, DeployRef $ref, Keys $keys, string $environment, \Closure $read): array
    {
        $none = ['sequence' => 0, 'manifest' => null, 'signed' => false];

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
            if (is_int($value) || (is_string($value) && preg_match('/^(\d+)(?::([0-9a-f]{64}))?$/', $value, $m) === 1)) {
                $sequence = is_int($value) ? $value : (strlen(ltrim($m[1], '0')) >= 19 ? PHP_INT_MAX : (int) $m[1]);
                if ($where === 'local' && $sequence <= time()) {
                    return ['sequence' => $sequence, 'manifest' => is_string($value) ? ($m[2] ?? null) : null, 'signed' => false];
                }
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

            return ['sequence' => $pointer->sequence, 'manifest' => $pointer->manifestSha256, 'signed' => true];
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
