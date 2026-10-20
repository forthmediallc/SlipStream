<?php

namespace Slipstream\Release;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Slipstream\Release\Core\ArtifactRefused;

/**
 * The artifact store, through the Laravel disk the app configures. Every
 * Flysystem call lives here. Under the prefix: releases/<sha>/release.tar.gz,
 * manifest.json and manifest.sig; and one pointer per deploy ref,
 * refs/heads/<branch>.json or refs/tags/<tag>.json.
 */
class ArtifactStore
{
    public const ARCHIVE = 'release.tar.gz';

    public const MANIFEST = 'manifest.json';

    public const SIGNATURE = 'manifest.sig';

    public const MAX_POINTER_BYTES = 65536;

    public const MAX_MANIFEST_BYTES = 67108864;

    protected string $disk;

    protected string $prefix;

    public function __construct(?string $disk = null, ?string $prefix = null)
    {
        $this->disk = trim($disk ?? (string) config('slipstream.artifacts.disk', ''));
        $this->prefix = trim($prefix ?? (string) config('slipstream.artifacts.prefix', ''), '/');
    }

    public function diskName(): string
    {
        return $this->disk;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Whether SLIPSTREAM_ARTIFACTS_DISK names a disk the app defines.
     */
    public function isConfigured(): bool
    {
        return $this->disk !== '' && is_array(config("filesystems.disks.{$this->disk}"));
    }

    public function pointerKey(DeployRef $ref): string
    {
        if ($ref->kind === DeployRef::TAGS) {
            throw new RuntimeException('Tag patterns are unsupported in artifact mode: name the ref CI publishes to (refs/heads/production or refs/tags/v1.2.0).');
        }

        return $this->key($ref->full().'.json');
    }

    public function releaseKey(string $sha, string $file): string
    {
        return $this->key('releases/'.strtolower($sha).'/'.$file);
    }

    /**
     * The pointer's envelope, or null when the ref has none yet.
     */
    public function readPointer(DeployRef $ref): ?string
    {
        $key = $this->pointerKey($ref);

        if (! $this->filesystem()->exists($key)) {
            return null;
        }

        return $this->readCapped($key, self::MAX_POINTER_BYTES, 'pointer');
    }

    public function readManifest(string $sha): string
    {
        return $this->readRelease($sha, self::MANIFEST, self::MAX_MANIFEST_BYTES, 'manifest');
    }

    public function readSignature(string $sha): string
    {
        return $this->readRelease($sha, self::SIGNATURE, self::MAX_POINTER_BYTES, 'statement');
    }

    public function hasRelease(string $sha): bool
    {
        return $this->filesystem()->exists($this->releaseKey($sha, self::SIGNATURE));
    }

    /**
     * Stream the archive to a local file, hashing as it goes, and give up
     * past the size the manifest declares rather than fill the disk.
     *
     * @return array{sha256: string, size: int}
     */
    public function downloadArchive(string $sha, string $to, int $maxBytes, ?Closure $progress = null): array
    {
        $key = $this->releaseKey($sha, self::ARCHIVE);
        $in = $this->filesystem()->readStream($key);
        if (! is_resource($in)) {
            throw new RuntimeException("No archive at {$key} on disk {$this->disk}.");
        }

        $out = @fopen($to, 'xb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException("Cannot write {$to}.");
        }

        $hash = hash_init('sha256');
        $size = 0;
        $reported = 0;

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException("Reading {$key} failed.");
                }
                if ($chunk === '') {
                    continue;
                }
                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    throw new ArtifactRefused('archive', "the archive exceeds the {$maxBytes} bytes the manifest declares", $sha);
                }
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = @fwrite($out, $offset === 0 ? $chunk : substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException("Cannot write {$to}; the disk may be full.");
                    }
                    $offset += $written;
                }

                if ($progress !== null && $size - $reported >= 16777216) {
                    $reported = $size;
                    $progress($size);
                }
            }
        } finally {
            $closed = fclose($out);
            fclose($in);
        }

        clearstatcache(true, $to);
        if (! $closed || @filesize($to) !== $size) {
            throw new RuntimeException("The archive did not land whole at {$to}; the disk may be full.");
        }

        return ['sha256' => hash_final($hash), 'size' => $size];
    }

    /**
     * Upload a release: the archive, then the manifest, then its statement.
     * The pointer that makes it live is written separately, last.
     *
     * Write-once: a published release is never replaced. Objects already
     * there with the same bytes (a re-run of the publish job) are skipped;
     * different bytes are refused, so no build can overwrite an older,
     * approved release and poison rollbacks. Through a filesystem disk this
     * is check-then-write; the standalone tool's conditional S3 writes, with
     * a bucket policy that requires them, are what make it hold.
     */
    public function putRelease(string $sha, string $dir, ?Closure $say = null): void
    {
        $filesystem = $this->filesystem();
        $say ??= fn (string $line) => null;
        $short = substr($sha, 0, 7);
        $refuse = fn (string $file) => new RuntimeException(
            "Release {$short} is already in the store with a different {$file}. A published release is never replaced: publish a new commit. "
            ."(If an earlier publish of this commit stopped half-way, remove releases/{$sha}/ with the break-glass role first.)"
        );

        $archivePath = $dir.DIRECTORY_SEPARATOR.self::ARCHIVE;
        if (! is_file($archivePath)) {
            throw new RuntimeException('No '.self::ARCHIVE." in {$dir}.");
        }

        $key = $this->releaseKey($sha, self::ARCHIVE);
        if ($filesystem->exists($key)) {
            if ($this->storedSha256($key) !== hash_file('sha256', $archivePath)) {
                throw $refuse(self::ARCHIVE);
            }
            $say('Already in the store: '.self::ARCHIVE);
        } else {
            $archive = fopen($archivePath, 'rb');
            if ($archive === false) {
                throw new RuntimeException("Cannot read {$archivePath}.");
            }
            try {
                if ($filesystem->writeStream($key, $archive) === false) {
                    throw new RuntimeException('Uploading '.self::ARCHIVE." to disk {$this->disk} failed.");
                }
            } finally {
                fclose($archive);
            }
            $say('Uploaded '.self::ARCHIVE);
        }

        foreach ([self::MANIFEST, self::SIGNATURE] as $file) {
            $contents = @file_get_contents($dir.DIRECTORY_SEPARATOR.$file);
            if ($contents === false) {
                throw new RuntimeException("No {$file} in {$dir}.");
            }
            $key = $this->releaseKey($sha, $file);
            if ($filesystem->exists($key)) {
                if ((string) $filesystem->get($key) !== $contents) {
                    throw $refuse($file);
                }
                $say("Already in the store: {$file}");

                continue;
            }
            if ($filesystem->put($key, $contents) === false) {
                throw new RuntimeException("Uploading {$file} to disk {$this->disk} failed.");
            }
            $say("Uploaded {$file}");
        }
    }

    public function putPointer(DeployRef $ref, string $envelope): void
    {
        if ($this->filesystem()->put($this->pointerKey($ref), $envelope) === false) {
            throw new RuntimeException('Writing the pointer '.$this->pointerKey($ref)." to disk {$this->disk} failed.");
        }
    }

    /**
     * Write the pointer only if it is still what the publisher read (null:
     * there was none). Through a filesystem disk the check and the write are
     * two calls, so two publishes can still race inside that window; run CI's
     * publishes in one concurrency group.
     */
    public function replacePointer(DeployRef $ref, ?string $expected, string $envelope): void
    {
        if ($this->readPointer($ref) !== $expected) {
            throw new RuntimeException('Another publish moved '.$this->pointerKey($ref).' while this one ran; nothing was pointed. Run the publish again.');
        }

        $this->putPointer($ref, $envelope);
    }

    protected function storedSha256(string $key): string
    {
        $in = $this->filesystem()->readStream($key);
        if (! is_resource($in)) {
            throw new RuntimeException("Cannot read {$key} on disk {$this->disk}.");
        }

        try {
            $hash = hash_init('sha256');
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException("Reading {$key} failed.");
                }
                hash_update($hash, $chunk);
            }

            return hash_final($hash);
        } finally {
            fclose($in);
        }
    }

    /**
     * The commits with a release in the store, newest listed last by the
     * disk's own order (needs ListBucket on S3).
     *
     * @return list<string>
     */
    public function releases(): array
    {
        $shas = [];

        foreach ($this->filesystem()->directories($this->key('releases')) as $directory) {
            $name = basename($directory);
            if (preg_match('/^[0-9a-f]{40}$/', $name) === 1) {
                $shas[] = $name;
            }
        }

        sort($shas, SORT_STRING);

        return $shas;
    }

    /**
     * A full commit id as given; a shorter prefix expanded against the
     * store's releases when it names exactly one.
     */
    public function resolveSha(string $shaOrPrefix): string
    {
        $value = strtolower(trim($shaOrPrefix));

        if (preg_match('/^[0-9a-f]{40}$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^[0-9a-f]{7,39}$/', $value) !== 1) {
            throw new RuntimeException("\"{$shaOrPrefix}\" is not a commit id.");
        }

        $matches = array_values(array_filter($this->releases(), fn (string $sha) => str_starts_with($sha, $value)));

        return match (count($matches)) {
            1 => $matches[0],
            0 => throw new RuntimeException("No release in the store starts with {$value}."),
            default => throw new RuntimeException("{$value} is ambiguous in the store: ".implode(', ', array_map(fn ($s) => substr($s, 0, 12), $matches))),
        };
    }

    protected function filesystem(): Filesystem
    {
        if ($this->disk === '') {
            throw new RuntimeException('SLIPSTREAM_ARTIFACTS_DISK is not set; name a disk from config/filesystems.php.');
        }
        if (! is_array(config("filesystems.disks.{$this->disk}"))) {
            throw new RuntimeException("SLIPSTREAM_ARTIFACTS_DISK names \"{$this->disk}\", which config/filesystems.php does not define.");
        }

        return Storage::disk($this->disk);
    }

    protected function key(string $path): string
    {
        return $this->prefix === '' ? $path : $this->prefix.'/'.$path;
    }

    protected function readRelease(string $sha, string $file, int $max, string $stage): string
    {
        $key = $this->releaseKey($sha, $file);

        if (! $this->filesystem()->exists($key)) {
            throw new RuntimeException("No {$file} for ".substr($sha, 0, 7)." at {$key} on disk {$this->disk}.");
        }

        return $this->readCapped($key, $max, $stage);
    }

    /**
     * Read a small object with a bound, so a swapped-in multi-gigabyte
     * pointer never reaches json_decode.
     */
    protected function readCapped(string $key, int $max, string $stage): string
    {
        $in = $this->filesystem()->readStream($key);
        if (! is_resource($in)) {
            throw new RuntimeException("Cannot read {$key} on disk {$this->disk}.");
        }

        try {
            $data = '';
            while (! feof($in) && strlen($data) <= $max) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw new RuntimeException("Reading {$key} failed.");
                }
                $data .= $chunk;
            }
        } finally {
            fclose($in);
        }

        if (strlen($data) > $max) {
            throw new ArtifactRefused($stage, basename($key)." exceeds {$max} bytes");
        }

        return $data;
    }
}
