<?php

namespace Slipstream\Release\Core;

/**
 * What a deploy ref points at in the store: one release, by commit and by
 * the hash of its manifest, with a sequence that only ever rises (a node
 * refuses an older pointer put back), the environment it belongs to (a
 * staging pointer copied into production's bucket verifies but is refused),
 * and the release before it, which is all a fresh node may fall back to.
 */
final class Pointer
{
    public const MAX_FUTURE_SECONDS = 86400;

    /**
     * @param  array{sha: string, manifest_sha256: string}|null  $previous
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $sha,
        public readonly string $manifestSha256,
        public readonly int $sequence,
        public readonly string $publishedAt,
        public readonly ?array $previous,
        public readonly string $by,
        public readonly string $environment,
        public readonly string $slipstream,
        public readonly string $keyId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ArtifactRefused when the payload is not a pointer
     */
    public static function fromPayload(array $payload, string $keyId): self
    {
        $refuse = fn (string $why) => throw new ArtifactRefused('pointer', 'the pointer is malformed: '.$why);

        $ref = $payload['ref'] ?? null;
        if (! is_string($ref) || preg_match('#^refs/(heads|tags)/[^\s]+$#', $ref) !== 1) {
            $refuse('ref');
        }
        $sha = $payload['sha'] ?? null;
        if (! is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            $refuse('sha');
        }
        $manifest = $payload['manifest_sha256'] ?? null;
        if (! is_string($manifest) || preg_match('/^[0-9a-f]{64}$/', $manifest) !== 1) {
            $refuse('manifest_sha256');
        }
        $sequence = $payload['sequence'] ?? null;
        if (! is_int($sequence) || $sequence < 1) {
            $refuse('sequence');
        }
        $publishedAt = $payload['published_at'] ?? null;
        if (! is_string($publishedAt) || $publishedAt === '' || strlen($publishedAt) > 64) {
            $refuse('published_at');
        }
        $environment = $payload['environment'] ?? null;
        if (! is_string($environment) || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $environment) !== 1) {
            $refuse('environment');
        }
        $by = $payload['by'] ?? null;
        if (! is_string($by) || strlen($by) > 255) {
            $refuse('by');
        }
        $slipstream = $payload['slipstream'] ?? null;
        if (! is_string($slipstream) || strlen($slipstream) > 64) {
            $refuse('slipstream');
        }

        $previous = $payload['previous'] ?? null;
        if ($previous !== null) {
            if (! is_array($previous)
                || ! is_string($previous['sha'] ?? null) || preg_match('/^[0-9a-f]{40}$/', $previous['sha']) !== 1
                || ! is_string($previous['manifest_sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $previous['manifest_sha256']) !== 1) {
                $refuse('previous');
            }
            $previous = ['sha' => $previous['sha'], 'manifest_sha256' => $previous['manifest_sha256']];
        }

        return new self($ref, $sha, $manifest, $sequence, $publishedAt, $previous, $by, $environment, $slipstream, $keyId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'by' => $this->by,
            'environment' => $this->environment,
            'manifest_sha256' => $this->manifestSha256,
            'previous' => $this->previous,
            'published_at' => $this->publishedAt,
            'ref' => $this->ref,
            'sequence' => $this->sequence,
            'sha' => $this->sha,
            'slipstream' => $this->slipstream,
        ];
    }

    public function short(): string
    {
        return substr($this->sha, 0, 7);
    }
}
