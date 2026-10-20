<?php

namespace Slipstream\Tests\Feature;

use RuntimeException;
use Slipstream\Release\Core\Manifest;
use Slipstream\Release\Core\TarReader;
use Slipstream\Release\Packager;
use Slipstream\Tests\Support\ArtifactFixture;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * slipstream:artifact package: a checkout into a release tarball and its
 * manifest, with the build-time hooks run and nothing a node must not have.
 */
class ArtifactPackageTest extends TestCase
{
    protected ArtifactFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = (new ArtifactFixture)->configure();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();

        parent::tearDown();
    }

    public function test_it_packages_a_checkout_into_a_manifest_and_archive(): void
    {
        $this->fixture->writeCheckout([
            '.git/HEAD' => 'ref: refs/heads/main',
            'node_modules/left-pad/index.js' => 'x',
            'tests/ExampleTest.php' => '<?php',
            '.env' => 'APP_KEY=secret',
            '.env.production' => 'APP_KEY=secret',
            'auth.json' => '{"github-oauth":{}}',
            'vendor/bin/phpunit' => '#!/bin/sh',
            'storage/logs/laravel.log' => 'noise',
            'bootstrap/cache/config.php' => '<?php return [];',
            'public/build/app.js' => 'built',
        ])->package();

        $manifest = Manifest::fromJson((string) file_get_contents($this->fixture->dist.'/manifest.json'));
        $paths = array_keys($manifest->files);

        foreach (['app/Hello.php', 'public/index.php', 'public/build/app.js', 'vendor/autoload.php', 'vendor/slipstream/slipstream/composer.json', 'storage/logs/.gitignore', 'bootstrap/cache/.gitignore', 'composer.lock'] as $kept) {
            $this->assertContains($kept, $paths);
        }
        foreach (['.git/HEAD', 'node_modules/left-pad/index.js', 'tests/ExampleTest.php', '.env', '.env.production', 'auth.json', 'vendor/bin/phpunit', 'storage/logs/laravel.log', 'bootstrap/cache/config.php'] as $dropped) {
            $this->assertNotContains($dropped, $paths);
        }

        $this->assertSame([hash('sha256', "<?php\n\nclass Hello {}\n"), strlen("<?php\n\nclass Hello {}\n")], $manifest->files['app/Hello.php']);
        $this->assertSame(hash_file('sha256', $this->fixture->dist.'/release.tar.gz'), $manifest->archiveSha256);
        $this->assertSame(filesize($this->fixture->dist.'/release.tar.gz'), $manifest->archiveSize);
        $this->assertSame(['production', 'refs/heads/production', PHP_VERSION, 'v0.6.0-test'], [$manifest->environment, $manifest->ref, $manifest->php, $manifest->slipstream]);
        $this->assertSame(['bootstrap/cache', 'public/storage', 'public/hot'], $manifest->generated);

        // The archive holds exactly the manifest's files.
        $out = $this->fixture->root.'/unpacked';
        mkdir($out);
        $extracted = TarReader::extract($this->fixture->dist.'/release.tar.gz', $out, [], $manifest->files);
        $this->assertSame($paths, array_keys($extracted));
    }

    public function test_an_output_directory_inside_the_checkout_is_left_out(): void
    {
        $this->fixture->writeCheckout(['dist/stale.txt' => 'from an earlier build']);

        $result = (new Packager)->package($this->fixture->checkout, [
            'sha' => $this->fixture->sha, 'ref' => 'refs/heads/production', 'environment' => 'production',
            'install' => false, 'output' => $this->fixture->checkout.'/dist',
        ], fn () => null);

        $this->assertArrayNotHasKey('dist/stale.txt', $result['manifest']->files);
        $this->assertArrayNotHasKey('dist/release.tar.gz', $result['manifest']->files);
    }

    public function test_build_hooks_run_in_step_order_and_node_hooks_do_not(): void
    {
        $this->fixture->writeCheckout([], <<<'PHP'
<?php

return [
    ['name' => 'After composer', 'step' => 'composer', 'where' => 'build', 'script' => 'echo three> built-3.txt'],
    ['name' => 'After fetch', 'step' => 'fetch', 'where' => 'build', 'script' => 'echo one> built-1.txt'],
    ['name' => 'Before composer', 'step' => 'composer', 'position' => 'before', 'where' => 'build', 'script' => 'echo two> built-2.txt'],
    ['name' => 'On the node', 'step' => 'composer', 'script' => 'echo node> node.txt'],
];
PHP)->package();

        $manifest = Manifest::fromJson((string) file_get_contents($this->fixture->dist.'/manifest.json'));

        $this->assertArrayHasKey('built-1.txt', $manifest->files);
        $this->assertArrayHasKey('built-2.txt', $manifest->files);
        $this->assertArrayHasKey('built-3.txt', $manifest->files);
        $this->assertArrayNotHasKey('node.txt', $manifest->files);
        $this->assertTrue(
            filemtime($this->fixture->checkout.'/built-1.txt') <= filemtime($this->fixture->checkout.'/built-3.txt'),
            'after-fetch before after-composer'
        );
    }

    public function test_without_a_hooks_file_the_build_assets_default_runs(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('the default hook is a POSIX shell script.');
        }

        $npm = $this->fixture->root.'/npm';
        file_put_contents($npm, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"$(dirname \"\$0\")/npm.log\"\n");
        chmod($npm, 0755);
        config()->set('slipstream.deploy.npm_binary', $npm);

        $this->fixture->writeCheckout(['package.json' => '{}', 'package-lock.json' => '{}'])->package();

        $this->assertSame("ci --no-audit --no-fund\nrun build\n", file_get_contents($this->fixture->root.'/npm.log'));
    }

    public function test_a_checkout_without_the_package_installed_is_refused(): void
    {
        $this->fixture->writeCheckout();
        $this->fixture->remove();
        mkdir($this->fixture->checkout, 0777, true);
        mkdir($this->fixture->dist, 0777, true);
        file_put_contents($this->fixture->checkout.'/artisan', '<?php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slipstream/slipstream is not installed in the checkout');

        $this->fixture->package();
    }

    public function test_a_symlink_in_the_tree_is_refused_by_name(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks.');
        }

        $this->fixture->writeCheckout();
        symlink($this->fixture->checkout.'/app', $this->fixture->checkout.'/public/storage-link');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('public/storage-link is a symlink');

        $this->fixture->package();
    }

    public function test_the_command_takes_the_commit_and_ref_from_git(): void
    {
        try {
            $git = (new Process(['git', '--version']))->run() === 0;
        } catch (\Throwable) {
            $git = false;
        }
        if (! $git) {
            $this->markTestSkipped('needs git on the PATH.');
        }

        $this->fixture->writeCheckout();
        $run = fn (string ...$args) => (new Process(['git', '-C', $this->fixture->checkout, '-c', 'user.name=Slipstream', '-c', 'user.email=slipstream@example.com', ...$args]))->mustRun()->getOutput();
        $run('init', '-q');
        $run('checkout', '-q', '-b', 'production');
        $run('add', '-A');
        $run('commit', '-q', '-m', 'release');
        $head = trim($run('rev-parse', 'HEAD'));

        $this->artisan('slipstream:artifact', ['action' => 'package', 'dir' => $this->fixture->dist, '--checkout' => $this->fixture->checkout, '--no-install' => true])
            ->expectsOutputToContain('refs/heads/production')
            ->assertExitCode(0);

        $manifest = Manifest::fromJson((string) file_get_contents($this->fixture->dist.'/manifest.json'));
        $this->assertSame($head, $manifest->sha);
        $this->assertSame('refs/heads/production', $manifest->ref);
        $this->assertSame(Packager::version(), $manifest->slipstream);
    }
}
