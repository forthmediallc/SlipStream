<?php

namespace Slipstream\Release;

use Closure;
use RuntimeException;
use Slipstream\Release\Core\ArtifactRefused;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\Manifest;
use Slipstream\Release\Core\SignatureException;
use Slipstream\Release\Core\Statement;
use Slipstream\Release\Core\TarReader;

/**
 * Brings one release from the store onto the node, checked at every step:
 * the manifest against the pointer that named it, the statement's signature
 * and what it signs, the PHP it needs, the archive's hash as it streams in,
 * every file's hash as it is written, and nothing in the archive the manifest
 * did not list. The first check that fails refuses the release.
 */
class ArtifactFetcher
{
    public const SIDECAR = '.slipstream';

    public function __construct(protected ArtifactStore $store) {}

    /**
     * @param  Closure(string): void  $say
     * @return array{manifest: Manifest, manifest_json: string, statement: Statement, signature: string, key_id: string, files: int}
     *
     * @throws ArtifactRefused  for a release the node will not take
     * @throws RuntimeException  when the store or the disk is the problem
     */
    public function fetchInto(string $sha, ?string $expectedManifestSha256, string $into, string $environment, string $ref, Keys $keys, Closure $say): array
    {
        $sha = strtolower($sha);
        $short = substr($sha, 0, 7);

        $json = $this->store->readManifest($sha);
        $manifestSha256 = hash('sha256', $json);
        if ($expectedManifestSha256 !== null && $manifestSha256 !== $expectedManifestSha256) {
            throw new ArtifactRefused('manifest', "the manifest's hash is not the one the pointer names", $sha, ['expected' => $expectedManifestSha256, 'actual' => $manifestSha256]);
        }

        $signature = $this->store->readSignature($sha);
        try {
            $verified = Envelope::verify($signature, Envelope::DOMAIN_MANIFEST, $keys);
        } catch (SignatureException $e) {
            throw new ArtifactRefused('statement', $e->getMessage(), $sha);
        }
        $statement = Statement::fromPayload($verified['payload'], $verified['key_id']);

        if ($statement->sha !== $sha) {
            throw new ArtifactRefused('statement', "the statement signs {$statement->sha}, not {$sha}", $sha);
        }
        if ($statement->manifestSha256 !== $manifestSha256 || $statement->manifestSize !== strlen($json)) {
            throw new ArtifactRefused('statement', 'the statement signs another manifest', $sha, ['expected' => $statement->manifestSha256, 'actual' => $manifestSha256]);
        }
        if ($statement->environment !== $environment) {
            throw new ArtifactRefused('statement', "it is signed for environment \"{$statement->environment}\"; this node is \"{$environment}\"", $sha);
        }
        if ($statement->ref !== $ref) {
            throw new ArtifactRefused('statement', "it was built for {$statement->ref}; this node deploys {$ref}", $sha);
        }

        $manifest = Manifest::fromJson($json);
        if ($manifest->sha !== $sha) {
            throw new ArtifactRefused('manifest', "the manifest is for {$manifest->sha}, not {$sha}", $sha);
        }
        if ($manifest->archiveSha256 !== $statement->archiveSha256) {
            throw new ArtifactRefused('statement', 'the statement signs another archive', $sha);
        }
        $say("Manifest {$short}: ".count($manifest->files).' files, built '.$manifest->builtAt.' with PHP '.$manifest->php.', signed by key '.$verified['key_id']);

        $node = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        if (version_compare($node, $manifest->phpSeries(), '<')) {
            throw new ArtifactRefused('php', "built for PHP {$manifest->phpSeries()}; this node runs {$node}", $sha);
        }
        if (version_compare($node, $manifest->phpSeries(), '>')) {
            $say("Built for PHP {$manifest->phpSeries()}; this node runs {$node}. Newer usually works; rebuild on {$node} to be sure.");
        }

        $maxArchive = (int) config('slipstream.artifacts.max_archive_bytes', 1073741824);
        if ($manifest->archiveSize > $maxArchive) {
            throw new ArtifactRefused('archive', "the archive is {$manifest->archiveSize} bytes; SLIPSTREAM_ARTIFACTS_MAX_ARCHIVE_BYTES is {$maxArchive}", $sha);
        }

        $need = $manifest->archiveSize + (int) ($manifest->unpackedBytes * 1.1) + 268435456;
        $free = @disk_free_space(dirname($into));
        if ($free !== false && $free < $need) {
            throw new RuntimeException(sprintf('Not enough disk space in %s: %d MB free, the release needs about %d MB.', dirname($into), intdiv((int) $free, 1048576), intdiv($need, 1048576)));
        }

        $tarball = rtrim($into, '/\\').'.tar.gz';
        try {
            $say("Downloading {$manifest->archiveName} (".number_format($manifest->archiveSize).' bytes)…');
            $download = $this->store->downloadArchive($sha, $tarball, $manifest->archiveSize, fn (int $bytes) => $say('  '.number_format($bytes).' bytes'));

            if ($download['size'] !== $manifest->archiveSize) {
                throw new ArtifactRefused('archive', "the archive is {$download['size']} bytes; the manifest says {$manifest->archiveSize}", $sha);
            }
            if ($download['sha256'] !== $manifest->archiveSha256) {
                throw new ArtifactRefused('archive', 'the archive does not match the signed manifest', $sha, ['expected' => $manifest->archiveSha256, 'actual' => $download['sha256']]);
            }

            $say('Unpacking and checking every file against the manifest…');
            // The manifest's own counts, so the reader inflates no more than
            // this release and its headers need.
            $files = TarReader::extract($tarball, $into, [
                'max_unpacked_bytes' => min($manifest->unpackedBytes, (int) config('slipstream.artifacts.max_unpacked_bytes', 4294967296)),
                'max_entries' => min($manifest->entries, (int) config('slipstream.artifacts.max_entries', 250000)),
            ], $manifest->files);
        } catch (ArtifactRefused $e) {
            throw new ArtifactRefused($e->stage, $e->reason, $sha, $e->context);
        } finally {
            @unlink($tarball);
        }

        $sidecar = rtrim($into, '/\\').DIRECTORY_SEPARATOR.self::SIDECAR;
        if (! is_dir($sidecar) && ! @mkdir($sidecar, 0755)) {
            throw new RuntimeException("Cannot create {$sidecar}.");
        }
        file_put_contents($sidecar.DIRECTORY_SEPARATOR.ArtifactStore::MANIFEST, $json);
        file_put_contents($sidecar.DIRECTORY_SEPARATOR.ArtifactStore::SIGNATURE, $signature);

        $say('Verified '.count($files).' files; the release matches its signed manifest.');

        return [
            'manifest' => $manifest,
            'manifest_json' => $json,
            'statement' => $statement,
            'signature' => $signature,
            'key_id' => $verified['key_id'],
            'files' => count($files),
        ];
    }
}
