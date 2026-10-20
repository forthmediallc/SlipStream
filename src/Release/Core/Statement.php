<?php

namespace Slipstream\Release\Core;

/**
 * What manifest.sig signs: not the manifest (it can run to megabytes, KMS
 * signs 4 KB) but its hash and size, the archive's hash, the commit, the ref
 * it was built for and the environment it belongs to.
 */
final class Statement
{
    public function __construct(
        public readonly string $sha,
        public readonly string $manifestSha256,
        public readonly int $manifestSize,
        public readonly string $archiveSha256,
        public readonly string $ref,
        public readonly string $environment,
        public readonly string $keyId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ArtifactRefused when the payload is not a statement
     */
    public static function fromPayload(array $payload, string $keyId): self
    {
        $refuse = fn (string $why) => throw new ArtifactRefused('statement', 'the manifest statement is malformed: '.$why);

        foreach (['sha' => 40, 'manifest_sha256' => 64, 'archive_sha256' => 64] as $field => $length) {
            if (! is_string($payload[$field] ?? null) || preg_match('/^[0-9a-f]{'.$length.'}$/', $payload[$field]) !== 1) {
                $refuse($field);
            }
        }
        if (! is_int($payload['manifest_size'] ?? null) || $payload['manifest_size'] < 1) {
            $refuse('manifest_size');
        }
        if (! is_string($payload['ref'] ?? null) || preg_match('#^refs/(heads|tags)/[^\s]+$#', $payload['ref']) !== 1) {
            $refuse('ref');
        }
        if (! is_string($payload['environment'] ?? null) || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $payload['environment']) !== 1) {
            $refuse('environment');
        }

        return new self(
            $payload['sha'],
            $payload['manifest_sha256'],
            $payload['manifest_size'],
            $payload['archive_sha256'],
            $payload['ref'],
            $payload['environment'],
            $keyId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'archive_sha256' => $this->archiveSha256,
            'environment' => $this->environment,
            'manifest_sha256' => $this->manifestSha256,
            'manifest_size' => $this->manifestSize,
            'ref' => $this->ref,
            'sha' => $this->sha,
        ];
    }
}
