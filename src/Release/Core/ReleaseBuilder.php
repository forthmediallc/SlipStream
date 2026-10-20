<?php

namespace Slipstream\Release\Core;

/**
 * The manifest and statement for an archive, derived from the archive's own
 * bytes. The job that signs builds the manifest itself rather than trust the
 * one the build job wrote: a compromised build can only alter what the
 * tarball holds, never claim files it does not.
 */
final class ReleaseBuilder
{
    /**
     * @param  array{slipstream: string, environment: string, sha: string, ref: string, built_at: string, php: string, generated: list<string>}  $meta
     * @param  array{max_unpacked_bytes?: int, max_entries?: int}  $limits
     * @param  (\Closure(string, array<string, array{0: string, 1: int}>): void)|null  $inspect  sees the unpacked tree before it is removed; throws to refuse
     */
    public static function manifestFromArchive(string $tarGz, array $meta, array $limits = [], ?\Closure $inspect = null): Manifest
    {
        $scratch = self::scratchDirectory();

        try {
            $files = TarReader::extract($tarGz, $scratch, $limits);
            if ($inspect !== null) {
                $inspect($scratch, $files);
            }
        } finally {
            self::removeTree($scratch);
        }

        ksort($files, SORT_STRING);
        $unpacked = 0;
        foreach ($files as [, $size]) {
            $unpacked += $size;
        }

        return new Manifest(
            $meta['slipstream'],
            $meta['environment'],
            $meta['sha'],
            $meta['ref'],
            $meta['built_at'],
            $meta['php'],
            basename($tarGz),
            hash_file('sha256', $tarGz),
            (int) filesize($tarGz),
            $unpacked,
            self::countEntries($files),
            $files,
            array_values($meta['generated']),
        );
    }

    /**
     * What publish runs on the archive it is about to sign: the same rules
     * the packager applies, now on bytes the build job cannot talk its way
     * around.
     *
     * @param  list<string>  $allow  paths allowed through on purpose
     * @param  (\Closure(string): void)|null  $warn  told about key material under vendor/
     * @return \Closure(string, array<string, array{0: string, 1: int}>): void
     */
    public static function inspector(array $allow = [], ?\Closure $warn = null): \Closure
    {
        return function (string $root, array $files) use ($allow, $warn): void {
            $inspection = ReleaseRules::inspect($root, array_map('strval', array_keys($files)), $allow);
            foreach ($inspection['warnings'] as $warning) {
                if ($warn !== null) {
                    $warn('Warning: '.$warning.' (vendor code ships; check it is a test fixture).');
                }
            }
            $problems = $inspection['problems'];
            if ($problems !== []) {
                throw new \RuntimeException('Refusing to publish: '.implode('; ', array_slice($problems, 0, 10))
                    .(count($problems) > 10 ? '; and '.(count($problems) - 10).' more' : '')
                    .'. Leave them out of the build (or name a path that may carry a key with --allow).');
            }
        };
    }

    /**
     * Everything a node will check about these bytes, checked before they
     * leave CI: the manifest parses as a node parses it, and both envelopes
     * verify under the keys the nodes hold. A KMS alias pointing at a key no
     * node trusts is found here, not by every node refusing the pointer.
     *
     * @throws \RuntimeException
     */
    public static function selfCheck(?string $manifestJson, ?string $statementEnvelope, ?string $pointerEnvelope, Keys $nodeKeys): void
    {
        if ($nodeKeys->count() === 0) {
            throw new \RuntimeException('No node keys to check against: pass --keys with the nodes\' SLIPSTREAM_RELEASE_PUBLIC_KEYS.');
        }

        if ($manifestJson !== null) {
            try {
                Manifest::fromJson($manifestJson);
            } catch (ArtifactRefused $e) {
                throw new \RuntimeException('Nodes would refuse this manifest: '.$e->reason);
            }
        }

        foreach ([[Envelope::DOMAIN_MANIFEST, $statementEnvelope, 'statement'], [Envelope::DOMAIN_POINTER, $pointerEnvelope, 'pointer']] as [$domain, $envelope, $what]) {
            if ($envelope === null) {
                continue;
            }
            try {
                Envelope::verify($envelope, $domain, $nodeKeys);
            } catch (SignatureException|ArtifactRefused $e) {
                throw new \RuntimeException("Nodes would refuse the {$what}: ".$e->getMessage().'. Is the signing key in the nodes\' SLIPSTREAM_RELEASE_PUBLIC_KEYS?');
            }
        }
    }

    /**
     * The statement that manifest.sig signs, for a manifest's exact bytes.
     */
    public static function statementFor(Manifest $manifest, string $manifestJson, string $environment): Statement
    {
        return new Statement(
            $manifest->sha,
            hash('sha256', $manifestJson),
            strlen($manifestJson),
            $manifest->archiveSha256,
            $manifest->ref,
            $environment,
            '',
        );
    }

    /**
     * Files plus the directory entries the writer emits for them.
     *
     * @param  array<string, array{0: string, 1: int}>  $files
     */
    private static function countEntries(array $files): int
    {
        $directories = [];
        foreach (array_keys($files) as $file) {
            $parts = explode('/', $file);
            array_pop($parts);
            for ($i = 1; $i <= count($parts); $i++) {
                $directories[implode('/', array_slice($parts, 0, $i))] = true;
            }
        }

        return count($files) + count($directories);
    }

    private static function scratchDirectory(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-release-'.bin2hex(random_bytes(6));
        if (! @mkdir($dir, 0700, true)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }

        return $dir;
    }

    private static function removeTree(string $path): void
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
                self::removeTree($path.DIRECTORY_SEPARATOR.$name);
            }
        }
        @rmdir($path);
    }
}
