<?php

namespace Slipstream\Release;

use Slipstream\Models\Deployment;
use Slipstream\Release\Core\ArtifactRefused;
use Slipstream\Release\Core\Baseline;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\Manifest;
use Slipstream\Release\Core\SignatureException;
use Slipstream\Release\Core\Statement;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;

/**
 * Whether a release on disk is still the release its manifest describes:
 * the weekly change-detection check (PCI DSS 11.5.2), the gate a rollback
 * applies before it flips onto an older release, and a doctor check. The
 * paths a node writes itself are left out.
 */
class ReleaseVerifier
{
    public function __construct(protected SettingsRepository $settings) {}

    /**
     * @return array{release: string, manifest: bool, sha: ?string, release_sha: ?string, signed: bool, key_id: ?string, signature_error: ?string, files: int, modified: list<string>, missing: list<string>, unlisted: list<string>, links: list<string>, generated: list<string>, baseline: bool, ignored: list<string>, linked: list<string>, ok: bool}
     */
    public function verify(string $release, ?string $manifestJson = null, ?string $signature = null): array
    {
        $release = rtrim($release, '/\\');
        $sidecar = $release.DIRECTORY_SEPARATOR.ArtifactFetcher::SIDECAR;
        $result = [
            'release' => $release, 'manifest' => false, 'sha' => null, 'release_sha' => null,
            'signed' => false, 'key_id' => null, 'signature_error' => null, 'files' => 0,
            'modified' => [], 'missing' => [], 'unlisted' => [], 'links' => [], 'generated' => [], 'baseline' => false,
            'ignored' => [], 'linked' => [], 'ok' => false,
        ];

        $manifestJson ??= is_file($sidecar.DIRECTORY_SEPARATOR.ArtifactStore::MANIFEST)
            ? (string) file_get_contents($sidecar.DIRECTORY_SEPARATOR.ArtifactStore::MANIFEST)
            : null;
        if ($manifestJson === null) {
            return $result;
        }

        try {
            $manifest = Manifest::fromJson($manifestJson);
        } catch (ArtifactRefused $e) {
            $result['signature_error'] = $e->reason;

            return $result;
        }
        $result['manifest'] = true;
        $result['sha'] = $manifest->sha;
        $result['files'] = count($manifest->files);

        $shaFile = $release.DIRECTORY_SEPARATOR.'RELEASE_SHA';
        $result['release_sha'] = is_file($shaFile) ? strtolower(trim((string) file_get_contents($shaFile))) : null;

        $signature ??= is_file($sidecar.DIRECTORY_SEPARATOR.ArtifactStore::SIGNATURE)
            ? (string) file_get_contents($sidecar.DIRECTORY_SEPARATOR.ArtifactStore::SIGNATURE)
            : null;
        if ($signature === null) {
            $result['signature_error'] = 'no manifest.sig beside the manifest';
        } else {
            try {
                $keys = Keys::parse((string) config('slipstream.artifacts.public_keys', ''));
                $verified = Envelope::verify($signature, Envelope::DOMAIN_MANIFEST, $keys);
                $statement = Statement::fromPayload($verified['payload'], $verified['key_id']);
                $environment = Slipstream::releaseEnvironment();

                if ($statement->manifestSha256 !== hash('sha256', $manifestJson) || $statement->manifestSize !== strlen($manifestJson)) {
                    $result['signature_error'] = 'the statement signs another manifest';
                } elseif ($statement->sha !== $manifest->sha) {
                    $result['signature_error'] = 'the statement signs another commit';
                } elseif ($environment === null && Slipstream::artifactMode()) {
                    $result['signature_error'] = 'SLIPSTREAM_RELEASE_ENVIRONMENT is not set, so the environment it was signed for cannot be checked';
                } elseif ($environment !== null && $statement->environment !== $environment) {
                    $result['signature_error'] = "signed for environment \"{$statement->environment}\"; this node is \"{$environment}\"";
                } else {
                    $result['signed'] = true;
                    $result['key_id'] = $verified['key_id'];
                }
            } catch (SignatureException|ArtifactRefused|\InvalidArgumentException $e) {
                $result['signature_error'] = $e->getMessage();
            }
        }

        // Each link the node makes must lead exactly where the deploy points
        // it (a storage link aimed at storage/logs, or a storage link aimed at
        // the app root, would serve logs, sessions or the .env); what the node
        // writes itself (the signed manifest's list, never the release's own
        // config, which ships inside the archive) excuses only unlisted
        // files. Every file CI signed is checked.
        $shared = [];
        foreach ($this->sharedPaths() as $path => $sharedPath) {
            $shared[trim(str_replace('\\', '/', (string) $path), '/')] = is_string($sharedPath) && $sharedPath !== '' ? $sharedPath : null;
        }
        $linked = array_values(array_unique(['storage', ...array_keys($shared)]));
        $ignored = array_values(array_unique(array_merge(Manifest::NODE_WRITTEN, $manifest->generated)));
        $result['linked'] = $linked;
        $result['ignored'] = $ignored;

        $targets = Manifest::linkTargets(Slipstream::appRoot(), $this->storageLinks(), $shared);
        $tree = $manifest->verifyTree($release, $linked, $ignored, $targets);
        $result['modified'] = $tree['modified'];
        $result['missing'] = $tree['missing'];
        $result['unlisted'] = $tree['unlisted'];
        $result['links'] = $tree['links'];

        // The caches and compiled views the node generated, against the
        // hashes the deploy recorded just before the swap. The record sits in
        // the release, as writable as the caches; the deployment row keeps
        // its hash, so a record deleted or rewritten after the deploy shows
        // up too.
        $baselineFile = $sidecar.DIRECTORY_SEPARATOR.Baseline::FILE;
        $recordedHash = $this->recordedBaselineHash($release, $manifest->sha);
        if (is_file($baselineFile)) {
            $contents = (string) file_get_contents($baselineFile);
            $baseline = Baseline::parse($contents);
            $result['baseline'] = true;
            if ($recordedHash !== null && hash('sha256', $contents) !== $recordedHash) {
                $result['generated'][] = Baseline::DIRECTORY.' (the recorded hashes were rewritten after the deploy)';
            }
            $result['generated'] = array_values(array_unique(array_merge(
                $result['generated'],
                $baseline === null ? [Baseline::DIRECTORY.' (the recorded hashes are unreadable)'] : Baseline::compare($release, $baseline['files'], $baseline['format']),
            )));
        } elseif ($recordedHash !== null) {
            $result['generated'][] = Baseline::DIRECTORY.' (the hashes recorded at deploy are gone)';
        }

        $result['ok'] = $result['signed']
            && $tree['modified'] === [] && $tree['missing'] === [] && $tree['unlisted'] === [] && $tree['links'] === []
            && $result['generated'] === []
            && ($result['release_sha'] === null || $result['release_sha'] === $manifest->sha);

        return $result;
    }

    /**
     * One line on why a release is not what its manifest says, or null.
     */
    public function problemWith(string $release): ?string
    {
        $result = $this->verify($release);

        if (! $result['manifest']) {
            return $result['signature_error'] ?? 'no manifest: not an artifact release';
        }
        if (! $result['signed']) {
            return 'its manifest does not verify: '.$result['signature_error'];
        }
        if ($result['release_sha'] !== null && $result['release_sha'] !== $result['sha']) {
            return "RELEASE_SHA says {$result['release_sha']}, the manifest {$result['sha']}";
        }

        $drift = [];
        foreach (['modified', 'missing', 'unlisted', 'links', 'generated'] as $kind) {
            if ($result[$kind] !== []) {
                $drift[] = count($result[$kind]).' '.$kind.' ('.implode(', ', array_slice($result[$kind], 0, 3)).(count($result[$kind]) > 3 ? ', …' : '').')';
            }
        }

        return $drift === [] ? null : 'files differ from the manifest: '.implode('; ', $drift);
    }

    /**
     * The hash of the baseline the deploy that put this release in place
     * recorded, from its deployment row; null when no row says.
     */
    protected function recordedBaselineHash(string $release, string $sha): ?string
    {
        try {
            $rows = Deployment::query()
                ->where('server_name', Slipstream::serverName())
                ->where('status', Deployment::STATUS_DEPLOYED)
                ->whereRaw('lower(sha) = ?', [strtolower($sha)])
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'release_path', 'meta']);
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            if (basename(str_replace('\\', '/', (string) $row->release_path)) === basename(str_replace('\\', '/', $release))) {
                $hash = $row->meta['artifact']['baseline_sha256'] ?? null;

                return is_string($hash) ? $hash : null;
            }
        }

        return null;
    }

    /**
     * The links `storage:link` makes and exactly where each leads, relative
     * to the app root: public/storage to storage/app/public, and whatever
     * filesystems.links adds into storage. A filesystems.links entry whose
     * target is outside storage is left out, so a link there is drift: what
     * lies outside storage ships in the release itself.
     *
     * @return array<string, string>
     */
    protected function storageLinks(): array
    {
        $links = Manifest::STORAGE_LINKS;

        try {
            $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
            $storage = rtrim(str_replace('\\', '/', storage_path()), '/').'/';
            foreach ((array) config('filesystems.links', []) as $link => $target) {
                $link = str_replace('\\', '/', (string) $link);
                $target = str_replace('\\', '/', (string) $target);
                if (str_starts_with($link, $base) && str_starts_with($target.'/', $storage)) {
                    $links[substr($link, strlen($base))] = 'storage/'.ltrim(substr($target, strlen($storage)), '/');
                }
            }
        } catch (\Throwable) {
            // No app to ask: the default only.
        }

        return $links;
    }

    /**
     * @return array<string, string>
     */
    protected function sharedPaths(): array
    {
        try {
            return $this->settings->sharedPaths();
        } catch (\Throwable) {
            return (array) config('slipstream.deploy.shared_paths', []);
        }
    }
}
