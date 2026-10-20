<?php

namespace Slipstream\Tests\Support;

use Illuminate\Support\Facades\Storage;
use Slipstream\Release\ArtifactStore;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Manifest;
use Slipstream\Release\Core\Pointer;
use Slipstream\Release\Core\ReleaseBuilder;
use Slipstream\Release\Core\SodiumSigner;
use Slipstream\Release\Core\Statement;
use Slipstream\Release\Core\TarReader;
use Slipstream\Release\DeployRef;
use Slipstream\Release\Packager;
use Slipstream\Release\Publisher;

/**
 * A checkout, packaged and published to a local disk, with the node side
 * configured to deploy it: what every artifact test starts from. The
 * mutators then do to the store what an attacker or a bug would.
 */
class ArtifactFixture
{
    public string $root;

    public string $checkout;

    public string $dist;

    public string $store;

    public string $appRoot;

    public SodiumSigner $signer;

    public string $publicKey;

    public string $keyId = 'test-key';

    public string $sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public ?Pointer $pointer = null;

    public function __construct(public string $environment = 'production', public string $ref = 'refs/heads/production')
    {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-artifact-'.bin2hex(random_bytes(4));
        $this->checkout = $this->root.DIRECTORY_SEPARATOR.'checkout';
        $this->dist = $this->root.DIRECTORY_SEPARATOR.'dist';
        $this->store = $this->root.DIRECTORY_SEPARATOR.'store';
        $this->appRoot = $this->root.DIRECTORY_SEPARATOR.'app';
        foreach ([$this->checkout, $this->dist, $this->store, $this->appRoot] as $dir) {
            mkdir($dir, 0777, true);
        }

        $pair = SodiumSigner::generate();
        $this->signer = SodiumSigner::fromSpec('sodium:'.$pair['secret'], $this->keyId);
        $this->publicKey = $pair['public'];
    }

    /**
     * The node side and the store, in config. Call before anything that
     * reads the store through the container.
     */
    public function configure(): static
    {
        config()->set('filesystems.disks.artifacts', ['driver' => 'local', 'root' => $this->store]);
        config()->set('slipstream.artifacts.disk', 'artifacts');
        config()->set('slipstream.artifacts.prefix', 'app');
        config()->set('slipstream.artifacts.public_keys', $this->keyId.':'.$this->publicKey);
        config()->set('slipstream.release.source', 'artifact');
        config()->set('slipstream.release.environment', $this->environment);
        config()->set('slipstream.trigger.mode', 'poll');
        config()->set('slipstream.trigger.ref', $this->ref);
        config()->set('slipstream.trigger.poll_jitter', 0);
        config()->set('slipstream.app_root', $this->appRoot);
        file_put_contents($this->appRoot.DIRECTORY_SEPARATOR.'.env', "APP_KEY=x\n");

        return $this;
    }

    /**
     * A small Laravel-shaped checkout: an artisan that records what ran it,
     * the package under vendor/, a few files, and optionally a hooks file.
     *
     * @param  array<string, string>  $files  extra files, path => contents
     */
    public function writeCheckout(array $files = [], ?string $hooksFile = null): static
    {
        $defaults = [
            'artisan' => <<<'PHP'
                <?php
                $sha = is_file(__DIR__.'/RELEASE_SHA') ? trim((string) file_get_contents(__DIR__.'/RELEASE_SHA')) : 'no-release';
                $root = getenv('SLIPSTREAM_APP_ROOT') ?: dirname(__DIR__);
                file_put_contents($root.'/ran.txt', $sha.' '.implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
                PHP,
            'composer.json' => '{"name":"acme/app","require":{"slipstream/slipstream":"^0.6"}}',
            'composer.lock' => '{"packages":[{"name":"slipstream/slipstream","version":"v0.6.0"}]}',
            'app/Hello.php' => "<?php\n\nclass Hello {}\n",
            'public/index.php' => "<?php\n\necho 'hello';\n",
            'config/app.php' => "<?php\n\nreturn ['name' => 'Acme'];\n",
            'vendor/autoload.php' => "<?php\n",
            'vendor/slipstream/slipstream/composer.json' => '{"name":"slipstream/slipstream"}',
            'storage/logs/.gitignore' => "*\n!.gitignore\n",
            'storage/framework/.gitignore' => "*\n!.gitignore\n",
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
        ];

        foreach ($files + $defaults as $path => $contents) {
            $file = $this->checkout.'/'.$path;
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $contents);
        }

        if ($hooksFile !== null) {
            file_put_contents($this->checkout.'/slipstream-hooks.php', $hooksFile);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->checkout.'/slipstream-hooks.php', true);
            }
        }

        return $this;
    }

    public function package(?string $sha = null): static
    {
        $this->sha = $sha ?? $this->sha;

        (new Packager)->package($this->checkout, [
            'sha' => $this->sha,
            'ref' => $this->ref,
            'environment' => $this->environment,
            'install' => false,
            'output' => $this->dist,
            'slipstream' => 'v0.6.0-test',
        ], fn () => null);

        return $this;
    }

    public function publish(string $by = 'ci'): Pointer
    {
        return $this->pointer = (new Publisher(new ArtifactStore))
            ->publish($this->dist, DeployRef::parse($this->ref), $this->sha, $this->signer, $by, $this->environment, fn () => null);
    }

    /**
     * A second release (another commit, another file), published on top of
     * the first: the pointer's previous is then the first.
     */
    public function publishSecond(string $sha = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'): Pointer
    {
        $first = $this->sha;
        $this->writeCheckout(['app/Hello.php' => "<?php\n\nclass Hello { /* v2 */ }\n"])->package($sha);
        $pointer = $this->publish();
        $this->firstSha = $first;

        return $pointer;
    }

    public ?string $firstSha = null;

    public function rollbackTo(string $sha, string $by = 'ci'): Pointer
    {
        return $this->pointer = (new Publisher(new ArtifactStore))
            ->rollback($sha, DeployRef::parse($this->ref), $this->signer, $by, $this->environment, fn () => null);
    }

    public function otherSigner(): SodiumSigner
    {
        return SodiumSigner::fromSpec('sodium:'.SodiumSigner::generate()['secret'], $this->keyId);
    }

    /**
     * What the store holds, read as a node would.
     */
    public function stored(string $file): string
    {
        return (string) Storage::disk('artifacts')->get('app/releases/'.$this->sha.'/'.$file);
    }

    public function tamperArchive(): void
    {
        $key = 'app/releases/'.$this->sha.'/'.ArtifactStore::ARCHIVE;
        $bytes = (string) Storage::disk('artifacts')->get($key);
        $position = intdiv(strlen($bytes), 2);
        $bytes[$position] = chr(ord($bytes[$position]) ^ 0xFF);
        Storage::disk('artifacts')->put($key, $bytes);
    }

    public function resignManifest(SodiumSigner $signer): void
    {
        $manifestJson = $this->stored(ArtifactStore::MANIFEST);
        $statement = ReleaseBuilder::statementFor(Manifest::fromJson($manifestJson), $manifestJson, $this->environment);
        Storage::disk('artifacts')->put('app/releases/'.$this->sha.'/'.ArtifactStore::SIGNATURE, Envelope::encode($statement->toPayload(), Envelope::DOMAIN_MANIFEST, $signer));
    }

    public function resignPointer(SodiumSigner $signer): void
    {
        $pointer = $this->pointer ?? throw new \LogicException('publish first');
        Storage::disk('artifacts')->put('app/'.$this->ref.'.json', Envelope::encode($pointer->toPayload(), Envelope::DOMAIN_POINTER, $signer));
    }

    /**
     * A manifest whose hash of one file is wrong, properly signed: what a
     * build whose archive and manifest disagree would publish.
     */
    public function publishWithFileHashMismatch(): void
    {
        $this->republishManifest(function (array $manifest): array {
            $first = array_key_first($manifest['files']);
            $manifest['files'][$first][0] = str_repeat('0', 64);

            return $manifest;
        });
    }

    /**
     * Rewrite the stored manifest, sign the result properly and re-point the
     * ref at it: a release that is what CI would have published had its
     * build said so.
     *
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     */
    public function republishManifest(\Closure $mutate): void
    {
        $data = json_decode($this->stored(ArtifactStore::MANIFEST), true);
        $json = Manifest::fromJson(json_encode($mutate($data)))->toJson();
        $manifest = Manifest::fromJson($json);
        $statement = ReleaseBuilder::statementFor($manifest, $json, $this->environment);

        Storage::disk('artifacts')->put('app/releases/'.$this->sha.'/'.ArtifactStore::MANIFEST, $json);
        Storage::disk('artifacts')->put('app/releases/'.$this->sha.'/'.ArtifactStore::SIGNATURE, Envelope::encode($statement->toPayload(), Envelope::DOMAIN_MANIFEST, $this->signer));

        $pointer = $this->pointer ?? throw new \LogicException('publish first');
        $this->pointer = new Pointer($pointer->ref, $pointer->sha, hash('sha256', $json), $pointer->sequence + 1, $pointer->publishedAt, $pointer->previous, $pointer->by, $pointer->environment, $pointer->slipstream, $pointer->keyId);
        Storage::disk('artifacts')->put('app/'.$this->ref.'.json', Envelope::encode($this->pointer->toPayload(), Envelope::DOMAIN_POINTER, $this->signer));
    }

    /**
     * The release as a node leaves it after a verified deploy: unpacked, with
     * the sidecar and RELEASE_SHA. For tests that need a release on disk
     * without running the deployer.
     */
    public function unpackInto(string $dir): string
    {
        @mkdir($dir, 0777, true);
        $manifest = Manifest::fromJson($this->stored(ArtifactStore::MANIFEST));
        TarReader::extract($this->dist.DIRECTORY_SEPARATOR.ArtifactStore::ARCHIVE, $dir, [], $manifest->files);
        mkdir($dir.'/.slipstream');
        file_put_contents($dir.'/.slipstream/'.ArtifactStore::MANIFEST, $this->stored(ArtifactStore::MANIFEST));
        file_put_contents($dir.'/.slipstream/'.ArtifactStore::SIGNATURE, $this->stored(ArtifactStore::SIGNATURE));
        file_put_contents($dir.'/RELEASE_SHA', $this->sha."\n");

        return $dir;
    }

    public function deletePointer(): void
    {
        Storage::disk('artifacts')->delete('app/'.$this->ref.'.json');
        $this->pointer = null;
    }

    public function statement(): Statement
    {
        $verified = Envelope::verify($this->stored(ArtifactStore::SIGNATURE), Envelope::DOMAIN_MANIFEST, \Slipstream\Release\Core\Keys::parse($this->keyId.':'.$this->publicKey));

        return Statement::fromPayload($verified['payload'], $verified['key_id']);
    }

    public function remove(): void
    {
        $this->removeTree($this->root);
    }

    protected function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0666);
            @unlink($path) || @rmdir($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        @chmod($path, 0777);
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path.DIRECTORY_SEPARATOR.$name);
            }
        }
        @rmdir($path);
    }
}
