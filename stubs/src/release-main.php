<?php

// The main program of stubs/slipstream-release.php: the Core classes above,
// driven from the command line, with no framework and no Composer. CI signs
// and publishes with it (the job that holds the signing key runs nothing
// else), slipstream-bootstrap.sh verifies the first release with it on a node
// that has no Slipstream code yet, and a root timer checks the live release
// with it from outside the tree the deploy user can write.

use Slipstream\Release\Core\ArtifactRefused;
use Slipstream\Release\Core\Baseline;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\KmsSigner;
use Slipstream\Release\Core\Manifest;
use Slipstream\Release\Core\Pointer;
use Slipstream\Release\Core\ReleaseBuilder;
use Slipstream\Release\Core\Signer;
use Slipstream\Release\Core\Signers;
use Slipstream\Release\Core\Statement;
use Slipstream\Release\Core\TarReader;

const SLIPSTREAM_RELEASE_USAGE = <<<'TXT'
slipstream-release.php — artifact releases without the framework

  verify-pointer  --ref=refs/heads/production --environment=production --keys=id:base64[,id:base64]|file:/abs/path
                  --pointer=<file> [--min-sequence=N]
                  Prints sha=, manifest_sha256=, sequence=, previous_sha=, previous_manifest_sha256=.

  verify-release  --sha=<40 hex> --ref=… --environment=… --keys=… --manifest=<file> --signature=<file>
                  --archive=<file> --extract-to=<empty dir> [--manifest-sha256=<hex>]
                  Verifies, unpacks, writes RELEASE_SHA and .slipstream/. Prints files=N.
                  On a refusal, removes what it unpacked.

  verify-tree     --release=<dir> --keys=… --environment=… --state-dir=<dir> [--app-root=<dir>]
                  [--shared=path[=shared/path],…] [--links=path=target,…] [--json]
                  The live release against its signed manifest and the cache hashes recorded at
                  deploy, from outside it: run it as root from a copy the deploy user cannot
                  write, with keys from a root-owned file, and with --state-dir, which pins
                  what it first saw (without it the in-tree record is only as safe as the tree).
                  Every link the deploy makes must lead exactly where it points them under the
                  app root (default: the directory holding releases/): storage, the .env,
                  auth.json, the shared paths given a target, public/storage to
                  storage/app/public, and each --links pair. Exit 0 clean, 1 drift, 2 usage.

  publish         --archive=<file> --manifest=<build manifest> --ref=… --environment=…
                  --sha=<40 hex> (default GITHUB_SHA) --keys=<the nodes' keys>
                  --key=sodium:…|file:…|kms:… [--key-id=…] --store=s3://bucket/prefix|file:///dir
                  [--by=…] [--generated=a,b] [--allow=a,b] [--max-unpacked-bytes=N] [--max-entries=N]
                  Regenerates the manifest from the archive, checks it as a node would, signs,
                  uploads write-once, then moves the pointer only if no other publish did.

  rollback        --sha=… --ref=… --environment=… --keys=… --key=… [--key-id=…] --store=… [--by=…]
                  Points the ref at a release already in the store.

Exit 0 on success, 1 on a refusal, drift or error (the reason on stderr), 2 on usage.
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

/**
 * @param  array<string, string|true>  $options
 */
function slipstream_release_option(array $options, string $name): ?string
{
    return isset($options[$name]) && $options[$name] !== true && $options[$name] !== '' ? (string) $options[$name] : null;
}

/**
 * @param  array<string, string|true>  $options
 * @param  list<string>  $default
 * @return list<string>
 */
function slipstream_release_list(array $options, string $name, array $default): array
{
    if (! isset($options[$name]) || $options[$name] === true) {
        return $default;
    }

    return array_values(array_filter(array_map(fn (string $item) => trim($item, " \t/"), explode(',', (string) $options[$name])), fn (string $item) => $item !== ''));
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

function slipstream_release_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            slipstream_release_remove_tree($path.'/'.$name);
        }
    }
    @rmdir($path);
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
 * @return array{0: string, 1: string}  bucket, key prefix ('' or 'prefix/')
 */
function slipstream_release_s3(string $store): array
{
    [$bucket, $prefix] = array_pad(explode('/', substr($store, 5), 2), 2, '');
    $prefix = trim($prefix, '/');
    if ($bucket === '') {
        slipstream_release_fail('--store must be s3://bucket/prefix or file:///dir', 2);
    }

    return [$bucket, $prefix === '' ? '' : $prefix.'/'];
}

function slipstream_release_file_path(string $store, string $key): string
{
    return rtrim(substr($store, 7), '/').'/'.$key;
}

function slipstream_release_is_conflict(\Throwable $e): bool
{
    return preg_match('/PreconditionFailed|ConditionalRequestConflict|\(412\)|\(409\)/i', $e->getMessage()) === 1;
}

/**
 * Download an object to a file. False when it is not there.
 *
 * @return string|false  the object's ETag (for file://, its content's SHA-1)
 */
function slipstream_release_store_fetch(string $store, string $key, string $to): string|false
{
    if (str_starts_with($store, 'file://')) {
        $path = slipstream_release_file_path($store, $key);
        if (! is_file($path)) {
            return false;
        }
        if (! copy($path, $to)) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        return (string) sha1_file($to);
    }
    if (str_starts_with($store, 's3://')) {
        [$bucket, $prefix] = slipstream_release_s3($store);
        try {
            $meta = json_decode(KmsSigner::cli(['aws', 's3api', 'get-object', '--bucket', $bucket, '--key', $prefix.$key, $to, '--output', 'json']), true);

            return is_array($meta) ? (string) ($meta['ETag'] ?? '') : '';
        } catch (\RuntimeException $e) {
            if (preg_match('/NoSuchKey|Not Found|\(404\)/i', $e->getMessage()) === 1) {
                return false;
            }
            throw $e;
        }
    }

    slipstream_release_fail('--store must be s3://bucket/prefix or file:///dir', 2);
}

/**
 * An object's contents and ETag, or [null, null] when it is not there.
 *
 * @return array{0: ?string, 1: ?string}
 */
function slipstream_release_store_get(string $store, string $key): array
{
    $tmp = (string) tempnam(sys_get_temp_dir(), 'ss-get-');
    try {
        $etag = slipstream_release_store_fetch($store, $key, $tmp);

        return $etag === false ? [null, null] : [(string) file_get_contents($tmp), $etag];
    } finally {
        @unlink($tmp);
    }
}

/**
 * Write-once. True when written; false when the very same bytes were
 * already there (a re-run of the publish job). Different bytes are refused:
 * a published release is never replaced.
 */
function slipstream_release_store_put_once(string $store, string $key, string $file): bool
{
    $refuse = fn () => new \RuntimeException("{$key} is already in the store with different content. A published release is never replaced: publish a new commit. (If an earlier publish of this commit stopped half-way, remove its releases/<sha>/ objects with the break-glass role first.)");

    if (str_starts_with($store, 'file://')) {
        $path = slipstream_release_file_path($store, $key);
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true)) {
            throw new \RuntimeException('Cannot create '.dirname($path).'.');
        }
        $out = @fopen($path, 'xb');
        if ($out === false) {
            if (is_file($path) && hash_file('sha256', $path) === hash_file('sha256', $file)) {
                return false;
            }
            throw is_file($path) ? $refuse() : new \RuntimeException("Cannot write {$path}.");
        }
        $in = fopen($file, 'rb');
        $copied = $in !== false ? stream_copy_to_stream($in, $out) : false;
        if ($in !== false) {
            fclose($in);
        }
        if (! fclose($out) || $copied !== filesize($file)) {
            @unlink($path);
            throw new \RuntimeException("Cannot write {$path}.");
        }

        return true;
    }
    if (str_starts_with($store, 's3://')) {
        [$bucket, $prefix] = slipstream_release_s3($store);
        try {
            KmsSigner::cli(['aws', 's3api', 'put-object', '--bucket', $bucket, '--key', $prefix.$key, '--body', $file, '--if-none-match', '*', '--output', 'json']);

            return true;
        } catch (\RuntimeException $e) {
            if (! slipstream_release_is_conflict($e)) {
                throw $e;
            }
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'ss-cmp-');
        try {
            if (slipstream_release_store_fetch($store, $key, $tmp) !== false && hash_file('sha256', $tmp) === hash_file('sha256', $file)) {
                return false;
            }
        } finally {
            @unlink($tmp);
        }
        throw $refuse();
    }

    slipstream_release_fail('--store must be s3://bucket/prefix or file:///dir', 2);
}

/**
 * Write the pointer only if it is still what this publish read: If-Match on
 * the ETag it read, If-None-Match when there was none. Two publishes can
 * never both win, so two pointers never share a sequence.
 */
function slipstream_release_store_put_pointer(string $store, string $key, string $file, ?string $etag): void
{
    $moved = fn () => new \RuntimeException("Another publish moved {$key} while this one ran; nothing was pointed. Run the publish again.");

    if (str_starts_with($store, 'file://')) {
        $path = slipstream_release_file_path($store, $key);
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true)) {
            throw new \RuntimeException('Cannot create '.dirname($path).'.');
        }
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Cannot lock {$path}.");
        }
        try {
            $now = is_file($path) ? (string) sha1_file($path) : null;
            if ($now !== $etag) {
                throw $moved();
            }
            $tmp = $path.'.tmp-'.bin2hex(random_bytes(4));
            if (! copy($file, $tmp) || ! rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException("Cannot write {$path}.");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return;
    }
    if (str_starts_with($store, 's3://')) {
        [$bucket, $prefix] = slipstream_release_s3($store);
        $argv = ['aws', 's3api', 'put-object', '--bucket', $bucket, '--key', $prefix.$key, '--body', $file, '--output', 'json'];
        $argv = array_merge($argv, $etag === null ? ['--if-none-match', '*'] : ['--if-match', $etag]);
        try {
            KmsSigner::cli($argv);
        } catch (\RuntimeException $e) {
            throw slipstream_release_is_conflict($e) ? $moved() : $e;
        }

        return;
    }

    slipstream_release_fail('--store must be s3://bucket/prefix or file:///dir', 2);
}

/**
 * Write the pointer for a ref: the sequence rises past the current one (or
 * now), a publish remembers the pointer it replaces, a rollback does not;
 * checked under the nodes' keys before it is written.
 *
 * @param  array<string, string|true>  $options
 */
function slipstream_release_point(array $options, string $sha, string $manifestSha256, bool $rollback, Keys $nodeKeys, Signer $signer): Pointer
{
    $ref = slipstream_release_need($options, 'ref');
    $environment = slipstream_release_need($options, 'environment');
    $store = slipstream_release_need($options, 'store');
    $by = slipstream_release_option($options, 'by') ?? (getenv('GITHUB_ACTOR') ?: get_current_user() ?: 'unknown');

    [$envelope, $etag] = slipstream_release_store_get($store, $ref.'.json');
    $current = null;
    if ($envelope !== null) {
        try {
            $current = slipstream_release_pointer($envelope, $ref, $environment, $nodeKeys->with($signer->keyId(), $signer->publicKey()));
        } catch (\Throwable $e) {
            slipstream_release_say('The current pointer does not verify ('.$e->getMessage().'); publishing without a previous.');
        }
    }

    $sequence = max(($current?->sequence ?? 0) + 1, time());
    $previous = (! $rollback && $current !== null && $current->sha !== $sha)
        ? ['sha' => $current->sha, 'manifest_sha256' => $current->manifestSha256]
        : null;

    $pointer = new Pointer($ref, $sha, $manifestSha256, $sequence, gmdate('Y-m-d\TH:i:s\Z'), $previous, $by, $environment, 'slipstream-release', $signer->keyId());
    $signed = Envelope::encode($pointer->toPayload(), Envelope::DOMAIN_POINTER, $signer);
    ReleaseBuilder::selfCheck(null, null, $signed, $nodeKeys);

    $file = (string) tempnam(sys_get_temp_dir(), 'ss-pointer-');
    file_put_contents($file, $signed);
    try {
        slipstream_release_store_put_pointer($store, $ref.'.json', $file, $envelope === null ? null : $etag);
    } finally {
        @unlink($file);
    }

    return $pointer;
}

/**
 * The live release against its signed manifest, and the caches the node
 * generated against the hashes the deploy recorded. With a state directory,
 * each release's manifest and recorded hashes are pinned the first time it
 * is seen, so rewriting the copies inside the tree is caught too.
 *
 * @param  array<string, string|null>  $shared  release path => shared path under the app root (null: not checked)
 * @param  array<string, string>  $storageLinks  release path => target under the app root
 * @return array<string, mixed>
 */
function slipstream_release_verify_tree(string $release, Keys $keys, string $environment, array $shared, ?string $stateDir, array $storageLinks = Manifest::STORAGE_LINKS, ?string $appRoot = null): array
{
    $result = [
        'release' => $release, 'sha' => null, 'ok' => false, 'reason' => null, 'files' => 0,
        'modified' => [], 'missing' => [], 'unlisted' => [], 'links' => [], 'generated' => [], 'baseline' => 'none',
        'app_root' => $appRoot, 'pinned' => $stateDir !== null,
    ];
    $sidecar = $release.'/.slipstream';

    $json = @file_get_contents($sidecar.'/manifest.json');
    $signature = @file_get_contents($sidecar.'/manifest.sig');
    if ($json === false || $signature === false) {
        $result['reason'] = 'no signed manifest in .slipstream/';

        return $result;
    }

    try {
        $manifest = Manifest::fromJson($json);
        $verified = Envelope::verify($signature, Envelope::DOMAIN_MANIFEST, $keys);
        $statement = Statement::fromPayload($verified['payload'], $verified['key_id']);
    } catch (\Throwable $e) {
        $result['reason'] = 'the manifest does not verify: '.$e->getMessage();

        return $result;
    }

    $result['sha'] = $manifest->sha;
    $result['files'] = count($manifest->files);
    $releaseSha = is_file($release.'/RELEASE_SHA') ? strtolower(trim((string) file_get_contents($release.'/RELEASE_SHA'))) : null;
    $result['reason'] = match (true) {
        $statement->manifestSha256 !== hash('sha256', $json) || $statement->manifestSize !== strlen($json) => 'the statement signs another manifest',
        $statement->sha !== $manifest->sha => 'the statement signs another commit',
        $statement->environment !== $environment => "it is signed for environment \"{$statement->environment}\", not \"{$environment}\"",
        $releaseSha !== $manifest->sha => 'RELEASE_SHA is not the manifest\'s commit',
        default => null,
    };

    // Every link the deploy makes leads exactly where it points it, under
    // the app root; without one, only the storage links can be checked (they
    // resolve through the release's own storage link).
    if ($appRoot !== null) {
        $linked = ['storage', ...array_keys($shared)];
        $targets = Manifest::linkTargets($appRoot, $storageLinks, $shared);
    } else {
        $linked = [...Manifest::NODE_LINKED, ...array_keys($shared)];
        $targets = [];
        foreach ($storageLinks as $path => $target) {
            $targets[$path] = $release.'/'.trim($target, '/');
        }
    }
    $tree = $manifest->verifyTree($release, $linked, array_merge(Manifest::NODE_WRITTEN, $manifest->generated), $targets);
    foreach (['modified', 'missing', 'unlisted', 'links'] as $kind) {
        $result[$kind] = $tree[$kind];
    }

    // Every deploy since 0.6.1 records the caches' hashes before the swap,
    // so a release without the record has lost it.
    $recorded = is_file($sidecar.'/'.Baseline::FILE) ? Baseline::fromJson((string) file_get_contents($sidecar.'/'.Baseline::FILE)) : null;
    if (! is_file($sidecar.'/'.Baseline::FILE)) {
        $result['generated'][] = Baseline::DIRECTORY.' (no hashes recorded: .slipstream/'.Baseline::FILE.' is missing; a release deployed by 0.6.1 or later always has one)';
    } elseif ($recorded === null) {
        $result['generated'][] = Baseline::DIRECTORY.' (the recorded hashes are unreadable)';
    }
    $baseline = $recorded;
    $result['baseline'] = $recorded !== null ? 'recorded' : 'none';

    if ($stateDir !== null) {
        if (! is_dir($stateDir) && ! @mkdir($stateDir, 0700, true)) {
            throw new \RuntimeException("Cannot create {$stateDir}.");
        }
        foreach (glob(rtrim($stateDir, '/').'/*.json') ?: [] as $old) {
            $pinned = json_decode((string) file_get_contents($old), true);
            if (is_array($pinned) && is_string($pinned['release'] ?? null) && ! is_dir($pinned['release'])) {
                @unlink($old); // that release is gone
            }
        }

        $pinFile = rtrim($stateDir, '/').'/'.sha1($release).'.json';
        $pin = is_file($pinFile) ? json_decode((string) file_get_contents($pinFile), true) : null;
        if (! is_array($pin)) {
            file_put_contents($pinFile, json_encode(['release' => $release, 'manifest_sha256' => hash('sha256', $json), 'generated' => $recorded, 'pinned_at' => gmdate('c')], JSON_UNESCAPED_SLASHES));
            @chmod($pinFile, 0600);
        } else {
            if (($pin['manifest_sha256'] ?? null) !== hash('sha256', $json)) {
                $result['reason'] ??= 'the release carries another manifest than when it was first checked';
            }
            if (($pin['generated'] ?? null) !== $recorded) {
                $result['generated'][] = Baseline::DIRECTORY.' (the recorded hashes changed since the release was first checked)';
            }
            $baseline = is_array($pin['generated'] ?? null) ? $pin['generated'] : null;
        }
        $result['baseline'] = $baseline !== null ? 'pinned' : 'none';
    }

    if ($baseline !== null) {
        $result['generated'] = array_values(array_unique(array_merge($result['generated'], Baseline::compare($release, $baseline))));
    }

    $result['ok'] = $result['reason'] === null
        && $result['modified'] === [] && $result['missing'] === [] && $result['unlisted'] === []
        && $result['links'] === [] && $result['generated'] === [];

    return $result;
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
                (int) (slipstream_release_option($options, 'min-sequence') ?? 0),
            );
            slipstream_release_say('sha='.$pointer->sha);
            slipstream_release_say('manifest_sha256='.$pointer->manifestSha256);
            slipstream_release_say('sequence='.$pointer->sequence);
            slipstream_release_say('previous_sha='.($pointer->previous['sha'] ?? ''));
            slipstream_release_say('previous_manifest_sha256='.($pointer->previous['manifest_sha256'] ?? ''));
            break;

        case 'verify-release':
            $sha = strtolower(slipstream_release_need($options, 'sha'));
            $ref = slipstream_release_need($options, 'ref');
            $environment = slipstream_release_need($options, 'environment');
            $keys = Keys::parse(slipstream_release_need($options, 'keys'));
            $into = rtrim(slipstream_release_need($options, 'extract-to'), '/\\');
            $json = slipstream_release_read(slipstream_release_need($options, 'manifest'), 67108864);
            $signature = slipstream_release_read(slipstream_release_need($options, 'signature'), Envelope::MAX_ENVELOPE);
            $archive = slipstream_release_need($options, 'archive');

            $manifestSha256 = hash('sha256', $json);
            $expected = slipstream_release_option($options, 'manifest-sha256');
            if ($expected !== null && $expected !== $manifestSha256) {
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
            if ($statement->ref !== $ref) {
                throw new ArtifactRefused('statement', "it was built for {$statement->ref}; this node deploys {$ref}");
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
            $created = ! is_dir($into);
            if ($created && ! @mkdir($into, 0755, true)) {
                slipstream_release_fail("cannot create {$into}");
            }

            try {
                $files = TarReader::extract($archive, $into, [
                    'max_unpacked_bytes' => min((int) (slipstream_release_option($options, 'max-unpacked-bytes') ?? PHP_INT_MAX), $manifest->unpackedBytes),
                    'max_entries' => min((int) (slipstream_release_option($options, 'max-entries') ?? 250000), $manifest->entries),
                ], $manifest->files);
                @mkdir($into.'/.slipstream', 0755);
                if (file_put_contents($into.'/.slipstream/manifest.json', $json) === false
                    || file_put_contents($into.'/.slipstream/manifest.sig', $signature) === false
                    || file_put_contents($into.'/RELEASE_SHA', $sha."\n") === false) {
                    throw new \RuntimeException("Cannot write the release's sidecar in {$into}.");
                }
            } catch (\Throwable $e) {
                // Nothing unverified stays behind.
                foreach (scandir($into) ?: [] as $name) {
                    if ($name !== '.' && $name !== '..') {
                        slipstream_release_remove_tree($into.'/'.$name);
                    }
                }
                if ($created) {
                    @rmdir($into);
                }
                throw $e;
            }
            slipstream_release_say('files='.count($files));
            break;

        case 'verify-tree':
            $release = slipstream_release_need($options, 'release');
            $real = realpath($release);
            if ($real === false || ! is_dir($real)) {
                slipstream_release_fail("no release at {$release}");
            }
            $real = str_replace('\\', '/', $real);
            $appRoot = slipstream_release_option($options, 'app-root');
            if ($appRoot === null && basename(dirname($real)) === 'releases') {
                $appRoot = dirname($real, 2);
            }
            // path[=target] pairs: the path in the release, the target under
            // the app root. A --links path needs its target (public/storage
            // has a default); a --shared path without one may link anywhere.
            $pairs = function (string $name, array $defaults) use ($options): array {
                $map = [];
                foreach (slipstream_release_list($options, $name, []) as $item) {
                    [$path, $target] = array_pad(explode('=', str_replace('\\', '/', $item), 2), 2, null);
                    $path = trim($path, '/');
                    $target ??= $defaults[$path] ?? null;
                    if ($path === '' || ($target === null && $name === 'links')) {
                        slipstream_release_fail("--{$name} takes path=target, the target relative to the app root (e.g. --links=public/media=storage/app/media)", 2);
                    }
                    if ($target !== null && (trim($target, '/') === '' || preg_match('#^(?:/|[A-Za-z]:)#', $target) === 1 || in_array('..', explode('/', $target), true))) {
                        slipstream_release_fail("--{$name}: the target for {$path} must be a path relative to the app root", 2);
                    }
                    $map[$path] = $target === null ? null : trim($target, '/');
                }

                return $map;
            };
            $storageLinks = $pairs('links', Manifest::STORAGE_LINKS) + Manifest::STORAGE_LINKS;
            $shared = $pairs('shared', []);
            $stateDir = slipstream_release_option($options, 'state-dir');
            if ($stateDir === null) {
                fwrite(STDERR, "slipstream-release: without --state-dir the cache record inside the release is only as safe as the release; for the 11.5.2 check pin it, e.g. --state-dir=/var/lib/slipstream-verify\n");
            }
            if ($appRoot === null) {
                fwrite(STDERR, "slipstream-release: no app root (pass --app-root): where storage, the .env, auth.json and the shared paths link to is not checked\n");
            }

            $result = slipstream_release_verify_tree(
                $real,
                Keys::parse(slipstream_release_need($options, 'keys')),
                slipstream_release_need($options, 'environment'),
                $shared,
                $stateDir,
                $storageLinks,
                $appRoot,
            );

            if (isset($options['json'])) {
                $line = ['event' => $result['ok'] ? 'artifact.verify_ok' : 'artifact.verify_drift'] + $result;
                foreach (['modified', 'missing', 'unlisted', 'links', 'generated'] as $kind) {
                    $line[$kind.'_count'] = count($result[$kind]);
                    $line[$kind] = array_slice($result[$kind], 0, 20);
                }
                slipstream_release_say((string) json_encode($line, JSON_UNESCAPED_SLASHES));
            } elseif ($result['ok']) {
                slipstream_release_say("ok: {$result['release']} ({$result['sha']}) matches its signed manifest: {$result['files']} files; generated caches ".($result['baseline'] === 'none' ? 'not recorded' : 'match the '.$result['baseline'].' hashes'));
            } else {
                slipstream_release_say("DRIFT: {$result['release']}".($result['sha'] ? " ({$result['sha']})" : '').($result['reason'] ? ": {$result['reason']}" : ''));
                foreach (['modified' => 'modified', 'missing' => 'missing', 'unlisted' => 'not in the manifest', 'links' => 'symlinks', 'generated' => 'generated since the deploy'] as $kind => $label) {
                    if ($result[$kind] !== []) {
                        slipstream_release_say('  '.$label.' ('.count($result[$kind]).'): '.implode(', ', array_slice($result[$kind], 0, 20)).(count($result[$kind]) > 20 ? ', …' : ''));
                    }
                }
            }
            exit($result['ok'] ? 0 : 1);

        case 'publish':
            $archive = slipstream_release_need($options, 'archive');
            $built = Manifest::fromJson(slipstream_release_read(slipstream_release_need($options, 'manifest'), 67108864));
            $ref = slipstream_release_need($options, 'ref');
            $environment = slipstream_release_need($options, 'environment');
            $store = slipstream_release_need($options, 'store');
            $sha = strtolower(slipstream_release_option($options, 'sha') ?? (getenv('GITHUB_SHA') ?: ''));
            if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
                slipstream_release_fail('--sha is required: the commit being published, by its full id (GitHub Actions sets GITHUB_SHA). The build job does not get to say which commit it built.', 2);
            }
            if ($built->sha !== $sha) {
                slipstream_release_fail("the build says it is {$built->sha}; this publish is for {$sha}");
            }
            if ($built->ref !== $ref) {
                slipstream_release_fail("the build is for {$built->ref}; the pointer is {$ref}");
            }
            $nodeKeys = Keys::parse(slipstream_release_need($options, 'keys'));
            $signer = Signers::fromSpec(slipstream_release_need($options, 'key'), slipstream_release_option($options, 'key-id'));

            $generated = slipstream_release_list($options, 'generated', Manifest::DEFAULT_GENERATED);
            foreach ($generated as $path) {
                if (! Manifest::validPath($path)) {
                    slipstream_release_fail('not a release path for --generated: '.$path, 2);
                }
            }

            slipstream_release_say('Reading the archive back to build the manifest to sign…');
            $manifest = ReleaseBuilder::manifestFromArchive($archive, [
                'slipstream' => $built->slipstream, 'environment' => $environment, 'sha' => $sha, 'ref' => $built->ref,
                'built_at' => $built->builtAt, 'php' => $built->php, 'generated' => $generated,
            ], [
                'max_unpacked_bytes' => (int) (slipstream_release_option($options, 'max-unpacked-bytes') ?? 4294967296),
                'max_entries' => (int) (slipstream_release_option($options, 'max-entries') ?? 250000),
            ], ReleaseBuilder::inspector(slipstream_release_list($options, 'allow', []), fn (string $line) => slipstream_release_say($line)));
            $json = $manifest->toJson();
            $statement = Envelope::encode(ReleaseBuilder::statementFor($manifest, $json, $environment)->toPayload(), Envelope::DOMAIN_MANIFEST, $signer);
            ReleaseBuilder::selfCheck($json, $statement, null, $nodeKeys);

            $dir = sys_get_temp_dir().'/ss-publish-'.bin2hex(random_bytes(4));
            mkdir($dir, 0700);
            try {
                file_put_contents($dir.'/manifest.json', $json);
                file_put_contents($dir.'/manifest.sig', $statement);
                foreach (['release.tar.gz' => $archive, 'manifest.json' => $dir.'/manifest.json', 'manifest.sig' => $dir.'/manifest.sig'] as $name => $file) {
                    slipstream_release_say((slipstream_release_store_put_once($store, 'releases/'.$sha.'/'.$name, $file) ? 'Uploaded ' : 'Already in the store: ').$name);
                }
            } finally {
                @unlink($dir.'/manifest.json');
                @unlink($dir.'/manifest.sig');
                @rmdir($dir);
            }
            slipstream_release_say('Release '.substr($sha, 0, 7).': '.count($manifest->files).' files, signed by key '.$signer->keyId());

            $pointer = slipstream_release_point($options, $sha, hash('sha256', $json), false, $nodeKeys, $signer);
            slipstream_release_say(sprintf('%s → %s (sequence %d%s)', $pointer->ref, $pointer->short(), $pointer->sequence, $pointer->previous ? ', previous '.substr($pointer->previous['sha'], 0, 7) : ''));
            break;

        case 'rollback':
            $sha = strtolower(slipstream_release_need($options, 'sha'));
            if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
                slipstream_release_fail('--sha must be the full 40-character commit id', 2);
            }
            $ref = slipstream_release_need($options, 'ref');
            $store = slipstream_release_need($options, 'store');
            $environment = slipstream_release_need($options, 'environment');
            $nodeKeys = Keys::parse(slipstream_release_need($options, 'keys'));
            $signer = Signers::fromSpec(slipstream_release_need($options, 'key'), slipstream_release_option($options, 'key-id'));

            [$signature] = slipstream_release_store_get($store, 'releases/'.$sha.'/manifest.sig');
            if ($signature === null) {
                slipstream_release_fail('no release '.substr($sha, 0, 7).' in the store');
            }
            $verified = Envelope::verify($signature, Envelope::DOMAIN_MANIFEST, $nodeKeys->with($signer->keyId(), $signer->publicKey()));
            $statement = Statement::fromPayload($verified['payload'], $verified['key_id']);
            if ($statement->sha !== $sha || $statement->environment !== $environment) {
                slipstream_release_fail('the release '.substr($sha, 0, 7).' carries a statement for another commit or environment');
            }
            if ($statement->ref !== $ref) {
                slipstream_release_fail('the release '.substr($sha, 0, 7)." was built for {$statement->ref}; nodes deploying {$ref} would refuse it");
            }

            $pointer = slipstream_release_point($options, $sha, $statement->manifestSha256, true, $nodeKeys, $signer);
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
