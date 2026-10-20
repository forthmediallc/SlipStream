<?php

namespace Slipstream\Release;

use Closure;
use RuntimeException;
use Slipstream\Diagnostics\ComposerLockCheck;
use Slipstream\Release\Core\Manifest;
use Slipstream\Release\Core\ReleaseRules;
use Slipstream\Release\Core\TarWriter;
use Symfony\Component\Process\Process;

/**
 * Builds a release from a checkout, in CI: composer install, the build-time
 * hooks, then everything a node needs — and nothing a node must not have —
 * into a deterministic tarball with a manifest of every file. The signing
 * job regenerates the manifest from the tarball before it signs, so what is
 * written here is for inspection, not trust.
 */
class Packager
{
    public const ARCHIVE = 'release.tar.gz';

    public const MANIFEST = 'manifest.json';

    /**
     * Never shipped: the repository's own metadata, build tooling, tests,
     * credentials, and what a node generates for itself. vendor/bin holds
     * symlinks on Linux, which an archive cannot carry; artisan never needs
     * it.
     *
     * @var list<string>
     */
    public const EXCLUDE = [
        '.git', '.github', '.gitattributes', '.gitignore', '.editorconfig',
        'node_modules', 'tests', '.phpunit.cache', '.phpunit.result.cache', 'phpunit.xml', 'phpunit.xml.dist',
        '.env', 'auth.json', 'vendor/bin',
        'public/hot', 'public/storage',
    ];

    /**
     * @param  array{sha: string, ref: string, environment: string, install: bool, output: string, slipstream?: string}  $options
     * @param  Closure(string): void  $say
     * @return array{manifest: Manifest, archive: string, manifest_path: string}
     */
    public function package(string $checkout, array $options, Closure $say): array
    {
        $checkout = rtrim(str_replace('\\', '/', realpath($checkout) ?: $checkout), '/');
        $output = rtrim(str_replace('\\', '/', $options['output']), '/');
        $sha = strtolower($options['sha']);

        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new RuntimeException('A release is packaged for one full commit id (--sha=<40 hex>).');
        }
        if (! is_dir($checkout)) {
            throw new RuntimeException("No checkout at {$checkout}.");
        }
        if (! is_dir($output) && ! @mkdir($output, 0755, true)) {
            throw new RuntimeException("Cannot create {$output}.");
        }
        // Resolved, so a relative dist/ inside the checkout is recognised
        // and left out (a previous run's archive must never ship).
        $output = rtrim(str_replace('\\', '/', realpath($output) ?: $output), '/');

        $say('Build hooks: after fetch');
        BuildHooks::run($checkout, $sha, $options['ref'], 'after-fetch', $say);
        $say('Build hooks: before composer');
        BuildHooks::run($checkout, $sha, $options['ref'], 'before-composer', $say);

        if ($options['install']) {
            $say('composer install…');
            $process = new Process([
                (string) config('slipstream.deploy.composer_binary', 'composer'), 'install',
                '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist', '--no-progress',
            ], $checkout, null, null, 1800);
            $process->run(function (string $type, string $chunk) use ($say) {
                foreach (preg_split("/\r\n|\n|\r/", $chunk) ?: [] as $line) {
                    if ($line !== '') {
                        $say('  '.$line);
                    }
                }
            });
            if (! $process->isSuccessful()) {
                throw new RuntimeException('composer install failed (exit '.$process->getExitCode().').');
            }
        }

        if (! is_dir($checkout.'/vendor/'.ComposerLockCheck::PACKAGE)) {
            throw new RuntimeException(ComposerLockCheck::PACKAGE.' is not installed in the checkout; a node runs the deploy from the release, so the package must be in it.');
        }

        $say('Build hooks: after composer');
        BuildHooks::run($checkout, $sha, $options['ref'], 'after-composer', $say);

        $dropped = [];
        $files = $this->files($checkout, $output, $dropped);
        if ($dropped !== []) {
            $say('Left out by name (never shipped): '.implode(', ', array_slice($dropped, 0, 20)).(count($dropped) > 20 ? ', and '.(count($dropped) - 20).' more' : ''));
        }

        $allow = array_values(array_filter((array) config('slipstream.artifacts.allow', []), 'is_string'));
        $problems = ReleaseRules::problems($checkout, $files, $allow);
        if ($problems !== []) {
            throw new RuntimeException('Refusing to package: '.implode('; ', array_slice($problems, 0, 10))
                .(count($problems) > 10 ? '; and '.(count($problems) - 10).' more' : '')
                .'. Keep key material out of the release (a secrets manager, or the node), or list the path in artifacts.allow.');
        }
        $say(count($files).' files');

        $archive = $output.'/'.self::ARCHIVE;
        @unlink($archive);
        $info = TarWriter::create($archive, $checkout, $files);

        $map = [];
        foreach ($files as $file) {
            $map[$file] = [hash_file('sha256', $checkout.'/'.$file), (int) filesize($checkout.'/'.$file)];
        }

        $manifest = new Manifest(
            $options['slipstream'] ?? self::version(),
            $options['environment'],
            $sha,
            $options['ref'],
            gmdate('Y-m-d\TH:i:s\Z'),
            PHP_VERSION,
            self::ARCHIVE,
            $info['sha256'],
            $info['size'],
            $info['unpacked_bytes'],
            $info['entries'],
            $map,
            array_values(array_filter((array) config('slipstream.artifacts.generated', []), 'is_string')),
        );

        $manifestPath = $output.'/'.self::MANIFEST;
        file_put_contents($manifestPath, $manifest->toJson());

        return ['manifest' => $manifest, 'archive' => $archive, 'manifest_path' => $manifestPath];
    }

    /**
     * The installed Slipstream version, for the manifest.
     */
    public static function version(): string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled(ComposerLockCheck::PACKAGE)) {
                return (string) \Composer\InstalledVersions::getPrettyVersion(ComposerLockCheck::PACKAGE);
            }
        } catch (\Throwable) {
            // Not installed through Composer (the package's own test suite).
        }

        return 'dev';
    }

    /**
     * Every regular file that ships, sorted, forward slashes.
     *
     * @param  list<string>  $dropped  what the never-ship name rules left out
     * @return list<string>
     */
    protected function files(string $checkout, string $output, array &$dropped = []): array
    {
        $exclude = array_merge(self::EXCLUDE, array_values(array_filter((array) config('slipstream.artifacts.exclude', []), 'is_string')));
        $exclude = array_map(fn (string $path) => trim(str_replace('\\', '/', $path), '/'), $exclude);

        if (str_starts_with($output.'/', $checkout.'/')) {
            $exclude[] = substr($output, strlen($checkout) + 1);
        }

        $files = [];
        $this->walk($checkout, '', $exclude, $files, $dropped);
        sort($files, SORT_STRING);
        sort($dropped, SORT_STRING);

        return $files;
    }

    /**
     * @param  list<string>  $exclude
     * @param  list<string>  $files
     * @param  list<string>  $dropped
     */
    protected function walk(string $checkout, string $relative, array $exclude, array &$files, array &$dropped = []): void
    {
        $dir = $relative === '' ? $checkout : $checkout.'/'.$relative;

        foreach (scandir($dir, SCANDIR_SORT_ASCENDING) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $relative === '' ? $name : $relative.'/'.$name;
            if (in_array($path, $exclude, true)) {
                continue;
            }
            // .git, node_modules, .env*, auth.json, .npmrc, … at any depth.
            if (ReleaseRules::neverShips($path)) {
                $dropped[] = $path;

                continue;
            }

            $absolute = $checkout.'/'.$path;

            if (is_link($absolute)) {
                throw new RuntimeException("{$path} is a symlink; a release carries regular files only. Let the node make it (storage:link runs there).");
            }

            if (is_dir($absolute)) {
                $this->walk($checkout, $path, $exclude, $files, $dropped);

                continue;
            }

            // Under storage/ only the skeleton ships: the .gitignore files
            // a first deploy seeds the shared storage from, never logs,
            // sessions or caches a developer left behind.
            if (str_starts_with($path, 'storage/') && $name !== '.gitignore') {
                continue;
            }
            // bootstrap/cache/*.php is what the node generates (config,
            // routes, packages); it must never ship.
            if (str_starts_with($path, 'bootstrap/cache/') && str_ends_with($name, '.php')) {
                continue;
            }

            if (! Manifest::validPath($path)) {
                throw new RuntimeException("{$path} cannot be a release path.");
            }

            $files[] = $path;
        }
    }
}
