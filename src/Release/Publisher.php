<?php

namespace Slipstream\Release;

use Closure;
use RuntimeException;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\Manifest;
use Slipstream\Release\Core\Pointer;
use Slipstream\Release\Core\ReleaseBuilder;
use Slipstream\Release\Core\Signer;
use Slipstream\Release\Core\SignatureException;
use Slipstream\Release\Core\Statement;
use Slipstream\Support\AuditLog;

/**
 * Puts a packaged release in the store and points a deploy ref at it.
 *
 * The build job is the untrusted side, so nothing it says is taken on its
 * word: the commit comes from the publish job (GITHUB_SHA), the manifest is
 * regenerated from the tarball, the paths a node may write are publish's own
 * list, the archive is checked for files that must never ship, and both
 * signatures are verified under the nodes' keys before anything uploads.
 * Releases are write-once; the pointer goes last, and only if no other
 * publish moved it meanwhile.
 *
 * Options: generated (list), allow (list), keys (Keys: the nodes' list;
 * default SLIPSTREAM_RELEASE_PUBLIC_KEYS).
 */
class Publisher
{
    public function __construct(protected ArtifactStore $store) {}

    /**
     * @param  Closure(string): void  $say
     * @param  array{generated?: list<string>, allow?: list<string>, keys?: Keys}  $options
     */
    public function publish(string $dist, DeployRef $ref, string $sha, Signer $signer, string $by, string $environment, Closure $say, array $options = []): Pointer
    {
        $this->store->pointerKey($ref); // a tag pattern has no pointer to write

        ['manifest' => $manifest, 'json' => $json] = $this->prepare($dist, $sha, $ref->full(), $signer, $environment, $say, $options);

        if ($manifest->ref !== $ref->full()) {
            throw new RuntimeException("The build is for {$manifest->ref}; the pointer is {$ref->full()}. Package it for the ref it is published to.");
        }

        $this->store->putRelease($manifest->sha, rtrim($dist, '/\\'), $say);

        return $this->point($ref, $manifest->sha, hash('sha256', $json), $signer, $by, $environment, false, $say, $options);
    }

    /**
     * Make a dist directory ready to publish: the manifest regenerated from
     * the archive, written back, and its statement signed into manifest.sig.
     *
     * @param  Closure(string): void  $say
     * @param  array{generated?: list<string>, allow?: list<string>, keys?: Keys}  $options
     * @return array{manifest: Manifest, json: string}
     */
    public function prepare(string $dist, string $sha, string $ref, Signer $signer, string $environment, Closure $say, array $options = []): array
    {
        $dist = rtrim($dist, '/\\');
        $archive = $dist.DIRECTORY_SEPARATOR.ArtifactStore::ARCHIVE;
        if (! is_file($archive)) {
            throw new RuntimeException('No '.ArtifactStore::ARCHIVE." in {$dist}; run slipstream:artifact package first.");
        }

        $sha = strtolower(trim($sha));
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new RuntimeException('Name the commit being published by its full id: --sha=<40 hex> (in GitHub Actions, GITHUB_SHA).');
        }

        $built = Manifest::fromJson((string) @file_get_contents($dist.DIRECTORY_SEPARATOR.ArtifactStore::MANIFEST));
        if ($built->sha !== $sha) {
            throw new RuntimeException("The build says it is {$built->sha}; this publish is for {$sha}. A build does not get to name its own commit.");
        }
        if ($built->ref !== $ref) {
            throw new RuntimeException("The build says it is for {$built->ref}; this publish is for {$ref}. A build does not get to name its own ref.");
        }

        $generated = array_values($options['generated'] ?? Manifest::DEFAULT_GENERATED);
        foreach ($generated as $path) {
            if (! is_string($path) || ! Manifest::validPath(trim($path, '/'))) {
                throw new RuntimeException('Not a release path for --generated: '.json_encode($path, JSON_UNESCAPED_SLASHES));
            }
        }
        $generated = array_map(fn (string $path) => trim($path, '/'), $generated);

        $say('Reading the archive back to build the manifest to sign…');
        $manifest = ReleaseBuilder::manifestFromArchive($archive, [
            'slipstream' => $built->slipstream,
            'environment' => $environment,
            'sha' => $sha,
            'ref' => $ref,
            'built_at' => $built->builtAt,
            'php' => $built->php,
            'generated' => $generated,
        ], [
            'max_unpacked_bytes' => (int) config('slipstream.artifacts.max_unpacked_bytes', 4294967296),
            'max_entries' => (int) config('slipstream.artifacts.max_entries', 250000),
        ], ReleaseBuilder::inspector(array_values($options['allow'] ?? []), $say));
        if ($manifest->files !== $built->files) {
            $say('The build job\'s manifest did not match the archive; the archive wins.');
        }

        $json = $manifest->toJson();
        $statement = ReleaseBuilder::statementFor($manifest, $json, $environment);
        $signature = Envelope::encode($statement->toPayload(), Envelope::DOMAIN_MANIFEST, $signer);

        // What every node will check, before anything leaves CI.
        ReleaseBuilder::selfCheck($json, $signature, null, $this->nodeKeys($options));

        file_put_contents($dist.DIRECTORY_SEPARATOR.ArtifactStore::MANIFEST, $json);
        file_put_contents($dist.DIRECTORY_SEPARATOR.ArtifactStore::SIGNATURE, $signature);
        $say('Signed the manifest statement with key '.$signer->keyId());

        return ['manifest' => $manifest, 'json' => $json];
    }

    /**
     * Point the ref at a release already in the store: a rollback, or a
     * roll-forward. Nothing is uploaded; the release must verify.
     *
     * @param  Closure(string): void  $say
     * @param  array{keys?: Keys}  $options
     */
    public function rollback(string $sha, DeployRef $ref, Signer $signer, string $by, string $environment, Closure $say, array $options = []): Pointer
    {
        $sha = $this->store->resolveSha($sha);
        if (! $this->store->hasRelease($sha)) {
            throw new RuntimeException('No release '.substr($sha, 0, 7).' in the store.');
        }

        $keys = $this->trustedKeys($signer);
        try {
            $verified = Envelope::verify($this->store->readSignature($sha), Envelope::DOMAIN_MANIFEST, $keys);
        } catch (SignatureException $e) {
            throw new RuntimeException('The release '.substr($sha, 0, 7).' does not verify: '.$e->getMessage());
        }
        $statement = Statement::fromPayload($verified['payload'], $verified['key_id']);
        if ($statement->sha !== $sha) {
            throw new RuntimeException('The release '.substr($sha, 0, 7).' carries a statement for another commit.');
        }
        if ($statement->environment !== $environment) {
            throw new RuntimeException('The release '.substr($sha, 0, 7)." was signed for {$statement->environment}, not {$environment}.");
        }
        if ($statement->ref !== $ref->full()) {
            throw new RuntimeException('The release '.substr($sha, 0, 7)." was built for {$statement->ref}; nodes deploying {$ref->full()} would refuse it.");
        }

        return $this->point($ref, $sha, $statement->manifestSha256, $signer, $by, $environment, true, $say, $options);
    }

    /**
     * The pointer a publish or rollback writes. A publish records the pointer
     * it replaces as `previous` (what a fresh node may fall back to); a
     * rollback records none, so nothing walks back onto what was rolled away
     * from. The sequence rises past the current one, or past now.
     *
     * @param  Closure(string): void  $say
     * @param  array{keys?: Keys}  $options
     */
    protected function point(DeployRef $ref, string $sha, string $manifestSha256, Signer $signer, string $by, string $environment, bool $rollback, Closure $say, array $options = []): Pointer
    {
        $before = $this->store->readPointer($ref);
        $current = $this->currentPointer($before, $ref, $signer, $environment, $say);
        $sequence = max(($current?->sequence ?? 0) + 1, time());
        $previous = (! $rollback && $current !== null && $current->sha !== $sha)
            ? ['sha' => $current->sha, 'manifest_sha256' => $current->manifestSha256]
            : null;

        $pointer = new Pointer($ref->full(), $sha, $manifestSha256, $sequence, gmdate('Y-m-d\TH:i:s\Z'), $previous, $by, $environment, Packager::version(), $signer->keyId());
        $envelope = Envelope::encode($pointer->toPayload(), Envelope::DOMAIN_POINTER, $signer);

        ReleaseBuilder::selfCheck(null, null, $envelope, $this->nodeKeys($options));
        $this->store->replacePointer($ref, $before, $envelope);

        AuditLog::record('artifact.published', [
            'ref' => $pointer->ref,
            'sha' => $pointer->sha,
            'sequence' => $pointer->sequence,
            'previous_sha' => $previous['sha'] ?? null,
            'key_id' => $pointer->keyId,
            'by' => $by,
            'rollback' => $rollback,
        ]);
        $say(sprintf('%s → %s (sequence %d%s)', $ref->full(), substr($sha, 0, 7), $sequence, $previous ? ', previous '.substr($previous['sha'], 0, 7) : ''));

        return $pointer;
    }

    protected function currentPointer(?string $envelope, DeployRef $ref, Signer $signer, string $environment, Closure $say): ?Pointer
    {
        if ($envelope === null) {
            return null;
        }

        try {
            $verified = Envelope::verify($envelope, Envelope::DOMAIN_POINTER, $this->trustedKeys($signer));
            $pointer = Pointer::fromPayload($verified['payload'], $verified['key_id']);
            if ($pointer->environment !== $environment || $pointer->ref !== $ref->full()) {
                throw new RuntimeException('it is for '.$pointer->ref.' in '.$pointer->environment);
            }

            return $pointer;
        } catch (\Throwable $e) {
            $say('The current pointer does not verify ('.$e->getMessage().'); publishing without a previous.');

            return null;
        }
    }

    /**
     * The keys nodes verify with: what a publish must pass before upload.
     *
     * @param  array{keys?: Keys}  $options
     */
    protected function nodeKeys(array $options): Keys
    {
        if (isset($options['keys'])) {
            return $options['keys'];
        }

        try {
            return Keys::parse((string) config('slipstream.artifacts.public_keys', ''));
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException('SLIPSTREAM_RELEASE_PUBLIC_KEYS: '.$e->getMessage());
        }
    }

    /**
     * The configured public keys, plus the signer's own: what it signs now
     * must verify even before the node list carries the key.
     */
    protected function trustedKeys(Signer $signer): Keys
    {
        try {
            $keys = Keys::parse((string) config('slipstream.artifacts.public_keys', ''));
        } catch (\InvalidArgumentException) {
            $keys = Keys::none();
        }

        return $keys->with($signer->keyId(), $signer->publicKey());
    }
}
