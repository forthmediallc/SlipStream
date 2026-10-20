<?php

// The main program of stubs/slipstream-release.php: the Core classes above,
// driven from the command line, with no framework and no Composer. CI signs
// and publishes with it (the job that holds the signing key runs nothing
// else), and slipstream-bootstrap.sh verifies the first release with it on a
// node that has no Slipstream code yet.

use Slipstream\Release\Core\ArtifactRefused;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\KmsSigner;
use Slipstream\Release\Core\Manifest;
use Slipstream\Release\Core\Pointer;
use Slipstream\Release\Core\ReleaseBuilder;
use Slipstream\Release\Core\Signers;
use Slipstream\Release\Core\Statement;
use Slipstream\Release\Core\TarReader;

const SLIPSTREAM_RELEASE_USAGE = <<<'TXT'
slipstream-release.php — artifact releases without the framework

  verify-pointer  --ref=refs/heads/production --environment=production --keys=id:base64[,id:base64]
                  --pointer=<file> [--min-sequence=N]
                  Prints sha=, manifest_sha256=, sequence=, previous_sha= on success.

  verify-release  --sha=<40 hex> --environment=… --keys=… --manifest=<file> --signature=<file>
                  --archive=<file> --extract-to=<empty dir> [--manifest-sha256=<hex>]
                  [--max-unpacked-bytes=N] [--max-entries=N]
                  Verifies, unpacks, writes RELEASE_SHA and .slipstream/. Prints files=N.

  publish         --archive=<file> --manifest=<build manifest> --ref=… --environment=…
                  --key=sodium:…|file:…|kms:… [--key-id=…] --store=s3://bucket/prefix|file:///dir
                  [--by=…] [--keys=…] [--generated=a,b]
                  Regenerates the manifest from the archive, signs, uploads, then writes the pointer.

  rollback        --sha=… --ref=… --environment=… --key=… --store=… [--by=…] [--keys=…]
                  Points the ref at a release already in the store.

Exit 0 on success, 1 on a refusal or an error (the reason on stderr), 2 on usage.
TXT;

/**
 * @return array<string, string|true>
 */
function slipstream_release_options(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (! str_starts_with($arg, '--')) {
            slipstream_release_fail("unexpected argument {$arg}", 2);
        }
        [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $options[$name] = $value;
    }

    return $options;
}

/**
 * @param  array<string, string|true>  $options
 */
function slipstream_release_need(array $options, string $name): string
{
    if (! isset($options[$name]) || $options[$name] === true || $options[$name] === '') {
        slipstream_release_fail("--{$name} is required", 2);
    }

    return (string) $options[$name];
}

function slipstream_release_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "slipstream-release: {$message}\n");
    exit($code);
}

function slipstream_release_say(string $line): void
{
    fwrite(STDOUT, $line."\n");
}

function slipstream_release_read(string $file, int $max): string
{
    if (! is_file($file)) {
        slipstream_release_fail("no such file: {$file}");
    }
    if (filesize($file) > $max) {
        slipstream_release_fail("{$file} exceeds {$max} bytes");
    }

    return (string) file_get_contents($file);
}

/**
 * A pointer that passes every check a node applies, or a refusal.
 */
function slipstream_release_pointer(string $envelope, string $ref, string $environment, Keys $keys, int $minSequence = 0): Pointer
{
    $verified = Envelope::verify($envelope, Envelope::DOMAIN_POINTER, $keys);
    $pointer = Pointer::fromPayload($verified['payload'], $verified['key_id']);

    if ($pointer->environment !== $environment) {
        throw new ArtifactRefused('pointer', "it is signed for environment \"{$pointer->environment}\"; this node is \"{$environment}\"");
    }
    if ($pointer->ref !== $ref) {
        throw new ArtifactRefused('pointer', "it names {$pointer->ref}, not {$ref}");
    }
    if ($pointer->sequence < $minSequence) {
        throw new ArtifactRefused('pointer', "sequence {$pointer->sequence} is below {$minSequence}, which this node already accepted");
    }
    if ($pointer->sequence > time() + Pointer::MAX_FUTURE_SECONDS) {
        throw new ArtifactRefused('pointer', "sequence {$pointer->sequence} is more than a day in the future");
    }

    return $pointer;
}

/**
 * Read an object from the store: a file under file:///dir, or an S3 object
 * through the AWS CLI. Null when it is not there.
 */
function slipstream_release_store_get(string $store, string $key): ?string
{
    if (str_starts_with($store, 'file://')) {
        $path = rtrim(substr($store, 7), '/').'/'.$key;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }
    if (str_starts_with($store, 's3://')) {
        try {
            return KmsSigner::cli(['aws', 's3', 'cp', rtrim($store, '/').'/'.$key, '-', '--quiet']);
        } catch (\RuntimeException $e) {
            if (preg_match('/404|NoSuchKey|does not exist|Not Found/i', $e->getMessage()) === 1) {
                return null;
            }
            throw $e;
        }
    }

    slipstream_release_fail('--store must be s3://bucket/prefix or file:///dir', 2);
}

function slipstream_release_store_put(string $store, string $key, string $file): void
{
    if (str_starts_with($store, 'file://')) {
        $path = rtrim(substr($store, 7), '/').'/'.$key;
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true)) {
            slipstream_release_fail('cannot create '.dirname($path));
        }
        if (! copy($file, $path)) {
            slipstream_release_fail("cannot write {$path}");
        }

        return;
    }
    if (str_starts_with($store, 's3://')) {
        KmsSigner::cli(['aws', 's3', 'cp', $file, rtrim($store, '/').'/'.$key, '--quiet']);

        return;
    }

    slipstream_release_fail('--store must be s3://bucket/prefix or file:///dir', 2);
}

/**
 * Write the pointer for a ref: the sequence rises past the current one (or
 * now), a publish remembers the pointer it replaces, a rollback does not.
 */
function slipstream_release_point(array $options, string $sha, string $manifestSha256, bool $rollback): Pointer
{
    $ref = slipstream_release_need($options, 'ref');
    $environment = slipstream_release_need($options, 'environment');
    $store = slipstream_release_need($options, 'store');
    $signer = Signers::fromSpec(slipstream_release_need($options, 'key'), isset($options['key-id']) ? (string) $options['key-id'] : null);
    $by = isset($options['by']) && $options['by'] !== true ? (string) $options['by'] : (getenv('GITHUB_ACTOR') ?: get_current_user() ?: 'unknown');

    $trusted = (isset($options['keys']) && $options['keys'] !== true ? Keys::parse((string) $options['keys'], 64) : Keys::none())
        ->with($signer->keyId(), $signer->publicKey());

    $current = null;
    $envelope = slipstream_release_store_get($store, $ref.'.json');
    if ($envelope !== null) {
        try {
            $current = slipstream_release_pointer($envelope, $ref, $environment, $trusted);
        } catch (\Throwable $e) {
            slipstream_release_say('The current pointer does not verify ('.$e->getMessage().'); publishing without a previous.');
        }
    }

    $sequence = max(($current?->sequence ?? 0) + 1, time());
    $previous = (! $rollback && $current !== null && $current->sha !== $sha)
        ? ['sha' => $current->sha, 'manifest_sha256' => $current->manifestSha256]
        : null;

    $pointer = new Pointer($ref, $sha, $manifestSha256, $sequence, gmdate('Y-m-d\TH:i:s\Z'), $previous, $by, $environment, 'slipstream-release', $signer->keyId());

    $file = tempnam(sys_get_temp_dir(), 'ss-pointer-');
    file_put_contents($file, Envelope::encode($pointer->toPayload(), Envelope::DOMAIN_POINTER, $signer));
    try {
        slipstream_release_store_put($store, $ref.'.json', $file);
    } finally {
        @unlink($file);
    }

    return $pointer;
}

$command = $argv[1] ?? null;
if ($command === null || in_array($command, ['-h', '--help', 'help'], true)) {
    fwrite($command === null ? STDERR : STDOUT, SLIPSTREAM_RELEASE_USAGE."\n");
    exit($command === null ? 2 : 0);
}
$options = slipstream_release_options(array_slice($argv, 2));

try {
    switch ($command) {
        case 'verify-pointer':
            $ref = slipstream_release_need($options, 'ref');
            if (preg_match('#^refs/(heads|tags)/[^\s*?\[]+$#', $ref) !== 1) {
                slipstream_release_fail('--ref must be one ref, refs/heads/<branch> or refs/tags/<tag>; a tag pattern has no pointer', 2);
            }
            $pointer = slipstream_release_pointer(
                slipstream_release_read(slipstream_release_need($options, 'pointer'), Envelope::MAX_ENVELOPE),
                $ref,
                slipstream_release_need($options, 'environment'),
                Keys::parse(slipstream_release_need($options, 'keys')),
                isset($options['min-sequence']) && $options['min-sequence'] !== true ? (int) $options['min-sequence'] : 0,
            );
            slipstream_release_say('sha='.$pointer->sha);
            slipstream_release_say('manifest_sha256='.$pointer->manifestSha256);
            slipstream_release_say('sequence='.$pointer->sequence);
            slipstream_release_say('previous_sha='.($pointer->previous['sha'] ?? ''));
            break;

        case 'verify-release':
            $sha = strtolower(slipstream_release_need($options, 'sha'));
            $environment = slipstream_release_need($options, 'environment');
            $keys = Keys::parse(slipstream_release_need($options, 'keys'));
            $into = rtrim(slipstream_release_need($options, 'extract-to'), '/\\');
            $json = slipstream_release_read(slipstream_release_need($options, 'manifest'), 67108864);
            $signature = slipstream_release_read(slipstream_release_need($options, 'signature'), Envelope::MAX_ENVELOPE);
            $archive = slipstream_release_need($options, 'archive');

            $manifestSha256 = hash('sha256', $json);
            if (isset($options['manifest-sha256']) && $options['manifest-sha256'] !== true && $options['manifest-sha256'] !== $manifestSha256) {
                throw new ArtifactRefused('manifest', "the manifest's hash is not the one the pointer names");
            }
            $verified = Envelope::verify($signature, Envelope::DOMAIN_MANIFEST, $keys);
            $statement = Statement::fromPayload($verified['payload'], $verified['key_id']);
            if ($statement->sha !== $sha) {
                throw new ArtifactRefused('statement', "the statement signs {$statement->sha}, not {$sha}");
            }
            if ($statement->manifestSha256 !== $manifestSha256 || $statement->manifestSize !== strlen($json)) {
                throw new ArtifactRefused('statement', 'the statement signs another manifest');
            }
            if ($statement->environment !== $environment) {
                throw new ArtifactRefused('statement', "it is signed for environment \"{$statement->environment}\"; this node is \"{$environment}\"");
            }
            $manifest = Manifest::fromJson($json);
            if ($manifest->sha !== $sha) {
                throw new ArtifactRefused('manifest', "the manifest is for {$manifest->sha}, not {$sha}");
            }
            if ($manifest->archiveSha256 !== $statement->archiveSha256) {
                throw new ArtifactRefused('statement', 'the statement signs another archive');
            }
            $node = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
            if (version_compare($node, $manifest->phpSeries(), '<')) {
                throw new ArtifactRefused('php', "built for PHP {$manifest->phpSeries()}; this node runs {$node}");
            }
            if (version_compare($node, $manifest->phpSeries(), '>')) {
                fwrite(STDERR, "slipstream-release: built for PHP {$manifest->phpSeries()}; this node runs {$node}\n");
            }
            if (! is_file($archive) || filesize($archive) !== $manifest->archiveSize) {
                throw new ArtifactRefused('archive', 'the archive is not the size the manifest says');
            }
            if (hash_file('sha256', $archive) !== $manifest->archiveSha256) {
                throw new ArtifactRefused('archive', 'the archive does not match the signed manifest');
            }
            if (is_dir($into) && count(scandir($into) ?: []) > 2) {
                slipstream_release_fail("{$into} is not empty");
            }
            if (! is_dir($into) && ! @mkdir($into, 0755, true)) {
                slipstream_release_fail("cannot create {$into}");
            }
            $files = TarReader::extract($archive, $into, [
                'max_unpacked_bytes' => isset($options['max-unpacked-bytes']) && $options['max-unpacked-bytes'] !== true ? (int) $options['max-unpacked-bytes'] : $manifest->unpackedBytes,
                'max_entries' => isset($options['max-entries']) && $options['max-entries'] !== true ? (int) $options['max-entries'] : 250000,
            ], $manifest->files);
            @mkdir($into.'/.slipstream', 0755);
            file_put_contents($into.'/.slipstream/manifest.json', $json);
            file_put_contents($into.'/.slipstream/manifest.sig', $signature);
            file_put_contents($into.'/RELEASE_SHA', $sha."\n");
            slipstream_release_say('files='.count($files));
            break;

        case 'publish':
            $archive = slipstream_release_need($options, 'archive');
            $built = Manifest::fromJson(slipstream_release_read(slipstream_release_need($options, 'manifest'), 67108864));
            $ref = slipstream_release_need($options, 'ref');
            $environment = slipstream_release_need($options, 'environment');
            if ($built->ref !== $ref) {
                slipstream_release_fail("the build is for {$built->ref}; the pointer is {$ref}");
            }
            if (isset($options['sha']) && $options['sha'] !== true && strtolower((string) $options['sha']) !== $built->sha) {
                slipstream_release_fail("the build is of {$built->sha}, not {$options['sha']}");
            }
            $signer = Signers::fromSpec(slipstream_release_need($options, 'key'), isset($options['key-id']) ? (string) $options['key-id'] : null);
            $generated = isset($options['generated']) && $options['generated'] !== true
                ? array_values(array_filter(array_map('trim', explode(',', (string) $options['generated']))))
                : $built->generated;

            slipstream_release_say('Reading the archive back to build the manifest to sign…');
            $manifest = ReleaseBuilder::manifestFromArchive($archive, [
                'slipstream' => $built->slipstream, 'environment' => $environment, 'sha' => $built->sha, 'ref' => $built->ref,
                'built_at' => $built->builtAt, 'php' => $built->php, 'generated' => $generated,
            ]);
            $json = $manifest->toJson();
            $statement = ReleaseBuilder::statementFor($manifest, $json, $environment);

            $dir = sys_get_temp_dir().'/ss-publish-'.bin2hex(random_bytes(4));
            mkdir($dir, 0700);
            try {
                file_put_contents($dir.'/manifest.json', $json);
                file_put_contents($dir.'/manifest.sig', Envelope::encode($statement->toPayload(), Envelope::DOMAIN_MANIFEST, $signer));
                $store = slipstream_release_need($options, 'store');
                slipstream_release_store_put($store, 'releases/'.$manifest->sha.'/release.tar.gz', $archive);
                slipstream_release_store_put($store, 'releases/'.$manifest->sha.'/manifest.json', $dir.'/manifest.json');
                slipstream_release_store_put($store, 'releases/'.$manifest->sha.'/manifest.sig', $dir.'/manifest.sig');
                slipstream_release_say('Uploaded release '.substr($manifest->sha, 0, 7).' ('.count($manifest->files).' files, signed by key '.$signer->keyId().')');
            } finally {
                @unlink($dir.'/manifest.json');
                @unlink($dir.'/manifest.sig');
                @rmdir($dir);
            }

            $pointer = slipstream_release_point($options, $manifest->sha, hash('sha256', $json), false);
            slipstream_release_say(sprintf('%s → %s (sequence %d%s)', $pointer->ref, $pointer->short(), $pointer->sequence, $pointer->previous ? ', previous '.substr($pointer->previous['sha'], 0, 7) : ''));
            break;

        case 'rollback':
            $sha = strtolower(slipstream_release_need($options, 'sha'));
            if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
                slipstream_release_fail('--sha must be the full 40-character commit id', 2);
            }
            $store = slipstream_release_need($options, 'store');
            $environment = slipstream_release_need($options, 'environment');
            $signer = Signers::fromSpec(slipstream_release_need($options, 'key'), isset($options['key-id']) ? (string) $options['key-id'] : null);
            $trusted = (isset($options['keys']) && $options['keys'] !== true ? Keys::parse((string) $options['keys'], 64) : Keys::none())
                ->with($signer->keyId(), $signer->publicKey());

            $signature = slipstream_release_store_get($store, 'releases/'.$sha.'/manifest.sig');
            if ($signature === null) {
                slipstream_release_fail('no release '.substr($sha, 0, 7).' in the store');
            }
            $verified = Envelope::verify($signature, Envelope::DOMAIN_MANIFEST, $trusted);
            $statement = Statement::fromPayload($verified['payload'], $verified['key_id']);
            if ($statement->sha !== $sha || $statement->environment !== $environment) {
                slipstream_release_fail('the release '.substr($sha, 0, 7).' carries a statement for another commit or environment');
            }

            $pointer = slipstream_release_point($options, $sha, $statement->manifestSha256, true);
            slipstream_release_say(sprintf('%s → %s (sequence %d, rollback)', $pointer->ref, $pointer->short(), $pointer->sequence));
            break;

        default:
            slipstream_release_fail("unknown command {$command}\n\n".SLIPSTREAM_RELEASE_USAGE, 2);
    }
} catch (ArtifactRefused $e) {
    slipstream_release_fail('refused ('.$e->stage.'): '.$e->reason);
} catch (\Throwable $e) {
    slipstream_release_fail($e->getMessage());
}
