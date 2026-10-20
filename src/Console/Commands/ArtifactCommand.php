<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Release\ArtifactSource;
use Slipstream\Release\ArtifactStore;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\Signer;
use Slipstream\Release\Core\Signers;
use Slipstream\Release\Core\SodiumSigner;
use Slipstream\Release\DeployRef;
use Slipstream\Release\Packager;
use Slipstream\Release\Publisher;
use Slipstream\Slipstream;
use Symfony\Component\Process\Process;

/**
 * Artifact releases, from the CI side: build a checkout into a release,
 * sign it, put it in the store and point a deploy ref at it. `keygen` makes
 * a local Ed25519 pair; `list` shows what the store holds.
 */
class ArtifactCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:artifact
                            {action : package | sign | publish | list | keygen}
                            {dir=dist : The dist directory: package writes it, sign and publish read it}
                            {--checkout= : package: the checkout to build (default: the current directory)}
                            {--sha= : The commit. package: default git rev-parse HEAD; publish and sign: required, default GITHUB_SHA, and the build must agree; publish --rollback: the release to point at}
                            {--generated= : publish, sign: paths a node writes itself, comma-separated (default bootstrap/cache,public/storage,public/hot)}
                            {--allow= : publish, sign: paths outside vendor/ allowed to carry key material, comma-separated}
                            {--keys= : publish, sign: the nodes\' public keys, to check they will accept what is signed (default: SLIPSTREAM_RELEASE_PUBLIC_KEYS)}
                            {--ref= : The ref CI publishes to, e.g. refs/heads/production (package: default GITHUB_REF or the checked-out branch; publish: the build\'s)}
                            {--environment= : The environment the release is signed for (default: SLIPSTREAM_RELEASE_ENVIRONMENT)}
                            {--no-install : package: skip composer install (already done)}
                            {--key= : Signing key: sodium:<base64 secret> | file:<path> | kms:<key id, ARN or alias> (default: SLIPSTREAM_SIGNING_KEY)}
                            {--key-id= : The key id written into signatures (default: derived from the public key)}
                            {--disk= : publish, list: the filesystem disk (default: SLIPSTREAM_ARTIFACTS_DISK)}
                            {--rollback : publish: point the ref at --sha, already in the store; uploads nothing}
                            {--by= : publish: who or what published (default: GITHUB_ACTOR, else the OS user)}
                            {--show-public-key : sign: print the SLIPSTREAM_RELEASE_PUBLIC_KEYS entry for the key and stop}
                            {--json : Machine-readable output (list)}';

    protected $description = 'Build, sign, publish and list artifact releases (CI side)';

    public function handle(): int
    {
        try {
            return match ($this->argument('action')) {
                'package' => $this->package(),
                'sign' => $this->sign(),
                'publish' => $this->publish(),
                'list' => $this->list(),
                'keygen' => $this->keygen(),
                default => $this->unknownAction(),
            };
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function package(): int
    {
        $checkout = (string) ($this->option('checkout') ?: getcwd());
        $sha = (string) ($this->option('sha') ?: $this->git($checkout, 'rev-parse', 'HEAD'));
        $ref = (string) ($this->option('ref') ?: getenv('GITHUB_REF') ?: $this->git($checkout, 'symbolic-ref', '-q', 'HEAD'));
        if ($ref === '') {
            throw new \RuntimeException('Which ref is this build for? Pass --ref=refs/heads/production (CI: GITHUB_REF).');
        }
        $ref = DeployRef::parse($ref);
        if ($ref->kind === DeployRef::TAGS) {
            throw new \RuntimeException('A release is built for one ref, not a pattern.');
        }

        $this->components->info("Packaging {$checkout}");
        $result = app(Packager::class)->package($checkout, [
            'sha' => $sha,
            'ref' => $ref->full(),
            'environment' => $this->environment(),
            'install' => ! $this->option('no-install'),
            'output' => (string) $this->argument('dir'),
        ], fn (string $line) => $this->line('  '.$line));

        $manifest = $result['manifest'];
        $this->components->twoColumnDetail('Commit', $manifest->sha);
        $this->components->twoColumnDetail('Ref', $manifest->ref);
        $this->components->twoColumnDetail('Environment', $manifest->environment);
        $this->components->twoColumnDetail('PHP', $manifest->php);
        $this->components->twoColumnDetail('Files', (string) count($manifest->files));
        $this->components->twoColumnDetail('Archive', $result['archive'].' · '.number_format($manifest->archiveSize).' bytes · '.$manifest->archiveSha256);
        $this->components->twoColumnDetail('Manifest', $result['manifest_path']);

        return self::SUCCESS;
    }

    protected function sign(): int
    {
        $signer = $this->signer();

        if ($this->option('show-public-key')) {
            $this->line('SLIPSTREAM_RELEASE_PUBLIC_KEYS='.Keys::format($signer->keyId(), $signer->publicKey()));

            return self::SUCCESS;
        }

        $prepared = app(Publisher::class)->prepare((string) $this->argument('dir'), $this->publishSha(), $signer, $this->environment(), fn (string $line) => $this->line('  '.$line), $this->publishOptions());

        $this->components->twoColumnDetail('Commit', $prepared['manifest']->sha);
        $this->components->twoColumnDetail('Key', $signer->keyId());
        $this->components->twoColumnDetail('Manifest', hash('sha256', $prepared['json']));

        return self::SUCCESS;
    }

    protected function publish(): int
    {
        $signer = $this->signer();
        $environment = $this->environment();
        $by = (string) ($this->option('by') ?: getenv('GITHUB_ACTOR') ?: get_current_user() ?: 'unknown');
        $publisher = new Publisher($this->store());
        $say = fn (string $line) => $this->line('  '.$line);

        if ($this->option('rollback')) {
            $sha = (string) $this->option('sha');
            $refOption = (string) $this->option('ref');
            if ($sha === '' || $refOption === '') {
                throw new \RuntimeException('A rollback names the release and the ref: --rollback --sha=<sha> --ref=refs/heads/production');
            }
            $pointer = $publisher->rollback($sha, DeployRef::parse($refOption), $signer, $by, $environment, $say, $this->publishOptions());
        } else {
            $dir = (string) $this->argument('dir');
            $ref = $this->option('ref')
                ? DeployRef::parse((string) $this->option('ref'))
                : DeployRef::parse(\Slipstream\Release\Core\Manifest::fromJson((string) @file_get_contents(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.ArtifactStore::MANIFEST))->ref);
            $pointer = $publisher->publish($dir, $ref, $this->publishSha(), $signer, $by, $environment, $say, $this->publishOptions());
        }

        $this->components->twoColumnDetail('Ref', $pointer->ref);
        $this->components->twoColumnDetail('Commit', $pointer->sha);
        $this->components->twoColumnDetail('Sequence', (string) $pointer->sequence);
        $this->components->twoColumnDetail('Previous', $pointer->previous['sha'] ?? 'none');
        $this->components->twoColumnDetail('Key', $pointer->keyId);
        $this->components->info(($this->option('rollback') ? 'Rolled back: ' : 'Published: ').$pointer->ref.' → '.$pointer->short().'.');

        return self::SUCCESS;
    }

    protected function list(): int
    {
        $store = $this->store();
        $ref = DeployRef::fromConfig();
        $state = ['ref' => $ref->full(), 'pointer' => null, 'error' => null, 'releases' => $store->releases()];

        try {
            $pointer = (new ArtifactSource($store, app(\Slipstream\Release\ArtifactSequence::class)))->pointer($ref, accept: false);
            $state['pointer'] = $pointer->toPayload() + ['key_id' => $pointer->keyId];
        } catch (\Throwable $e) {
            $state['error'] = $e->getMessage();
        }

        if ($this->option('json')) {
            $this->json($state);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Store', 'disk '.$store->diskName().($store->prefix() !== '' ? ' · prefix '.$store->prefix() : ''));
        if ($state['pointer'] !== null) {
            $p = $state['pointer'];
            $this->components->twoColumnDetail($state['ref'], substr($p['sha'], 0, 7).' · sequence '.$p['sequence'].' · published '.$p['published_at'].' by '.$p['by'].' · key '.$p['key_id']);
            if ($p['previous'] !== null) {
                $this->components->twoColumnDetail('Previous', substr($p['previous']['sha'], 0, 7));
            }
        } else {
            $this->components->twoColumnDetail($state['ref'], 'no usable pointer: '.$state['error']);
        }
        $this->components->twoColumnDetail('Releases', $state['releases'] === [] ? 'none' : implode(', ', array_map(fn ($s) => substr($s, 0, 7), array_slice($state['releases'], 0, 50))));

        return self::SUCCESS;
    }

    protected function keygen(): int
    {
        $pair = SodiumSigner::generate();
        $id = (string) ($this->option('key-id') ?: $pair['key_id']);

        $this->line('# CI (an environment secret, never committed):');
        $this->line('SLIPSTREAM_SIGNING_KEY=sodium:'.$pair['secret']);
        $this->line('# Every node (the shared .env):');
        $this->line('SLIPSTREAM_RELEASE_PUBLIC_KEYS='.$id.':'.$pair['public']);

        return self::SUCCESS;
    }

    protected function signer(): Signer
    {
        $spec = (string) ($this->option('key') ?: config('slipstream.artifacts.signing_key', ''));
        if (trim($spec) === '') {
            throw new \RuntimeException('No signing key: pass --key=… or set SLIPSTREAM_SIGNING_KEY (sodium:<base64>, file:<path> or kms:<key>).');
        }

        return Signers::fromSpec($spec, $this->option('key-id') ?: null);
    }

    /**
     * The commit being published, from the publish job — never the build's
     * word. GitHub Actions sets GITHUB_SHA.
     */
    protected function publishSha(): string
    {
        $sha = trim((string) ($this->option('sha') ?: getenv('GITHUB_SHA') ?: ''));
        if ($sha === '') {
            throw new \RuntimeException('Name the commit being published: --sha=<40 hex> (GitHub Actions sets GITHUB_SHA). The build job does not get to say which commit it built.');
        }

        return $sha;
    }

    /**
     * @return array{generated?: list<string>, allow?: list<string>, keys?: Keys}
     */
    protected function publishOptions(): array
    {
        $list = fn (string $name) => array_values(array_filter(array_map('trim', explode(',', (string) $this->option($name))), fn (string $item) => $item !== ''));
        $options = [];

        if ($this->option('generated') !== null) {
            $options['generated'] = $list('generated');
        }
        if ($this->option('allow') !== null) {
            $options['allow'] = $list('allow');
        }
        if ($this->option('keys') !== null) {
            $options['keys'] = Keys::parse((string) $this->option('keys'));
        }

        return $options;
    }

    protected function environment(): string
    {
        $environment = (string) ($this->option('environment') ?: Slipstream::releaseEnvironment() ?: '');
        if ($environment === '') {
            throw new \RuntimeException('Which environment is this release for? Pass --environment=production or set SLIPSTREAM_RELEASE_ENVIRONMENT.');
        }

        return $environment;
    }

    protected function store(): ArtifactStore
    {
        return new ArtifactStore($this->option('disk') ?: null);
    }

    protected function git(string $checkout, string ...$args): string
    {
        $process = new Process(['git', '-C', $checkout, ...$args]);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }

    protected function unknownAction(): int
    {
        $this->components->error('Unknown action "'.$this->argument('action').'". Use: package, sign, publish, list, keygen.');

        return self::FAILURE;
    }
}
