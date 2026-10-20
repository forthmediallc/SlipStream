<?php

namespace Slipstream\Release;

use RuntimeException;
use Slipstream\Release\Core\ArtifactRefused;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\Pointer;
use Slipstream\Release\Core\SignatureException;
use Slipstream\Slipstream;
use Slipstream\Support\AuditLog;

/**
 * The release source of artifact mode: the deploy ref resolves to whatever
 * its signed pointer in the store names. A pointer that fails any check —
 * signature, environment, ref, sequence — is refused and audited, and the
 * node keeps serving what it serves.
 */
class ArtifactSource implements ReleaseHistory, ReleaseSource
{
    /** @var array<string, Pointer> */
    protected array $pointers = [];

    public function __construct(protected ArtifactStore $store, protected ArtifactSequence $sequence) {}

    public function latest(DeployRef $ref): ReleaseRef
    {
        $pointer = $this->pointer($ref);

        return new ReleaseRef($pointer->sha, $ref->name, $ref->isTag());
    }

    /**
     * The ref's pointer, verified and accepted (its sequence recorded).
     * Memoised per instance; the poller resolves a fresh instance per tick.
     *
     * @throws ArtifactRefused  for a pointer the node will not follow
     * @throws RuntimeException  when the store cannot be read, or is misconfigured
     */
    public function pointer(DeployRef $ref, bool $fresh = false, bool $accept = true): Pointer
    {
        if (! $fresh && isset($this->pointers[$ref->key()])) {
            return $this->pointers[$ref->key()];
        }

        if ($ref->kind === DeployRef::TAGS) {
            throw new RuntimeException('Tag patterns are unsupported in artifact mode: name the ref CI publishes to (refs/heads/production or refs/tags/v1.2.0).');
        }

        $environment = Slipstream::releaseEnvironment();
        if ($environment === null) {
            throw new RuntimeException('SLIPSTREAM_RELEASE_ENVIRONMENT is not set; a release is signed for one environment.');
        }

        $keys = $this->keys();
        $envelope = $this->store->readPointer($ref);
        if ($envelope === null) {
            throw new RuntimeException('No pointer at '.$this->store->pointerKey($ref).' on disk '.$this->store->diskName().'; publish a release first (slipstream:artifact publish).');
        }

        try {
            $verified = Envelope::verify($envelope, Envelope::DOMAIN_POINTER, $keys);
            $pointer = Pointer::fromPayload($verified['payload'], $verified['key_id']);
        } catch (SignatureException $e) {
            $this->refuse($ref, 'pointer', $e->getMessage());
        } catch (ArtifactRefused $e) {
            $this->refuse($ref, $e->stage, $e->reason);
        }

        if ($pointer->environment !== $environment) {
            $this->refuse($ref, 'pointer', "it is signed for environment \"{$pointer->environment}\"; this node is \"{$environment}\"", ['key_id' => $pointer->keyId]);
        }
        if ($pointer->ref !== $ref->full()) {
            $this->refuse($ref, 'pointer', "it names {$pointer->ref}, not {$ref->full()}", ['key_id' => $pointer->keyId]);
        }

        $highWater = $this->sequence->highWater($ref);
        if ($pointer->sequence < $highWater) {
            $this->refuse($ref, 'pointer', "sequence {$pointer->sequence} is below {$highWater}, which this node already accepted; publish a rollback rather than put an old pointer back", ['key_id' => $pointer->keyId, 'sha' => $pointer->sha]);
        }
        if ($pointer->sequence > time() + Pointer::MAX_FUTURE_SECONDS) {
            $this->refuse($ref, 'pointer', "sequence {$pointer->sequence} is more than a day in the future", ['key_id' => $pointer->keyId, 'sha' => $pointer->sha]);
        }

        // A read-only look (status, doctor) checks but records nothing.
        if ($accept && $pointer->sequence > $highWater) {
            $this->sequence->advance($ref, $pointer->sequence);
            AuditLog::record('artifact.accepted', [
                'ref' => $pointer->ref,
                'sha' => $pointer->sha,
                'sequence' => $pointer->sequence,
                'manifest_sha256' => $pointer->manifestSha256,
                'key_id' => $pointer->keyId,
                'by' => $pointer->by,
                'previous_sha' => $pointer->previous['sha'] ?? null,
            ]);
        }

        return $this->pointers[$ref->key()] = $pointer;
    }

    /**
     * A fresh node may fall back to the pointer's `previous` and nothing
     * else: the store suggests, the signed pointer vouches.
     */
    public function contains(DeployRef $ref, ReleaseRef $head, string $sha): bool
    {
        $sha = strtolower(trim($sha));

        if ($sha === strtolower($head->sha)) {
            return true;
        }

        try {
            $pointer = $this->pointer($ref);
        } catch (\Throwable) {
            return false;
        }

        return $pointer->previous !== null && $pointer->previous['sha'] === $sha;
    }

    public function keys(): Keys
    {
        try {
            return Keys::parse((string) config('slipstream.artifacts.public_keys', ''));
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    public function store(): ArtifactStore
    {
        return $this->store;
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    protected function refuse(DeployRef $ref, string $stage, string $reason, array $context = []): never
    {
        AuditLog::record('artifact.refused', ['stage' => $stage, 'reason' => $reason, 'ref' => $ref->full()] + $context);

        throw new ArtifactRefused($stage, $reason, $context['sha'] ?? null, $context);
    }
}
