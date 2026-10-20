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
                fwrite($out, $chunk);

                if ($progress !== null && $size - $reported >= 16777216) {
                    $reported = $size;
                    $progress($size);
                }
            }
        } finally {
            fclose($out);
            fclose($in);
        }

        return ['sha256' => hash_final($hash), 'size' => $size];
    }

    /**
     * Upload a release: the archive, then the manifest, then its statement.
     * The pointer that makes it live is written separately, last.
     */
    public function putRelease(string $sha, string $dir, ?Closure $say = null): void
    {
        $filesystem = $this->filesystem();

        $archive = fopen($dir.DIRECTORY_SEPARATOR.self::ARCHIVE, 'rb');
        if ($archive === false) {
            throw new RuntimeException('No '.self::ARCHIVE." in {$dir}.");
        }
        try {
            if ($filesystem->writeStream($this->releaseKey($sha, self::ARCHIVE), $archive) === false) {
                throw new RuntimeException('Uploading '.self::ARCHIVE." to disk {$this->disk} failed.");
            }
        } finally {
            fclose($archive);
        }
        if ($say !== null) {
            $say('Uploaded '.self::ARCHIVE);
        }

        foreach ([self::MANIFEST, self::SIGNATURE] as $file) {
            $contents = @file_get_contents($dir.DIRECTORY_SEPARATOR.$file);
            if ($contents === false) {
                throw new RuntimeException("No {$file} in {$dir}.");
            }
            if ($filesystem->put($this->releaseKey($sha, $file), $contents) === false) {
                throw new RuntimeException("Uploading {$file} to disk {$this->disk} failed.");
            }
            if ($say !== null) {
                $say("Uploaded {$file}");
            }
        }
    }

    public function putPointer(DeployRef $ref, string $envelope): void
    {
        if ($this->filesystem()->put($this->pointerKey($ref), $envelope) === false) {
            throw new RuntimeException('Writing the pointer '.$this->pointerKey($ref)." to disk {$this->disk} failed.");
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
