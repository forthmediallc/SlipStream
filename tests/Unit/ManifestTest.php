<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Release\Core\ArtifactRefused;
use Slipstream\Release\Core\Manifest;
use Slipstream\Tests\TestCase;

class ManifestTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-manifest-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_it_parses_and_serialises_deterministically(): void
    {
        $manifest = $this->manifest(['b.txt' => ['sha' => str_repeat('b', 64), 'size' => 2], 'a/x.txt' => ['sha' => str_repeat('a', 64), 'size' => 1]]);

        $json = $manifest->toJson();
        $again = Manifest::fromJson($json);

        $this->assertSame($json, $again->toJson());
        $this->assertSame(['a/x.txt', 'b.txt'], array_keys($again->files), 'sorted');
        $this->assertSame('8.4', $again->phpSeries());
        $this->assertSame(str_repeat('a', 64), $again->files['a/x.txt'][0]);
        $this->assertSame(['bootstrap/cache/config.php'], $again->generated);
    }

    public function test_a_manifest_that_is_not_one_is_refused_with_the_field(): void
    {
        $good = json_decode($this->manifest([])->toJson(), true);

        foreach ([
            [['format' => 2], 'format 2 is not 1'],
            [['sha' => 'short'], 'sha'],
            [['php' => 'eight'], 'php'],
            [['archive' => ['name' => 'release.tar.gz']], 'archive'],
            [['files' => ['../x' => [str_repeat('a', 64), 1]]], 'file path "../x"'],
            [['files' => ['a\\b' => [str_repeat('a', 64), 1]]], 'file path'],
            [['files' => ['/abs' => [str_repeat('a', 64), 1]]], 'file path'],
            [['files' => ['a//b' => [str_repeat('a', 64), 1]]], 'file path'],
            [['files' => ['ok' => ['nothex', 1]]], 'file ok'],
            [['files' => ['App.php' => [str_repeat('a', 64), 1], 'app.php' => [str_repeat('b', 64), 1]]], 'differ only in case'],
            [['generated' => ['..']], 'generated path'],
        ] as [$change, $message]) {
            try {
                Manifest::fromJson(json_encode(array_merge($good, $change)));
                $this->fail("accepted a manifest with {$message}");
            } catch (ArtifactRefused $e) {
                $this->assertSame('manifest', $e->stage);
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        try {
            Manifest::fromJson('{');
            $this->fail('accepted non-JSON');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('not JSON', $e->getMessage());
        }
    }

    public function test_verify_tree_reports_modified_missing_and_unlisted_and_ignores_what_a_node_writes(): void
    {
        mkdir($this->root.'/app');
        mkdir($this->root.'/bootstrap/cache', 0777, true);
        file_put_contents($this->root.'/app/Hello.php', 'hello');
        file_put_contents($this->root.'/index.php', 'index');
        file_put_contents($this->root.'/gone.txt', 'gone');
        file_put_contents($this->root.'/bootstrap/cache/config.php', 'cache');

        $manifest = $this->manifest([
            'app/Hello.php' => ['sha' => hash('sha256', 'hello'), 'size' => 5],
            'index.php' => ['sha' => hash('sha256', 'index'), 'size' => 5],
            'gone.txt' => ['sha' => hash('sha256', 'gone'), 'size' => 4],
        ]);

        $this->assertSame(['modified' => [], 'missing' => [], 'unlisted' => [], 'links' => []], $manifest->verifyTree($this->root, ['bootstrap/cache']));

        file_put_contents($this->root.'/index.php', 'tampered');
        unlink($this->root.'/gone.txt');
        file_put_contents($this->root.'/app/shell.php', 'dropped');
        file_put_contents($this->root.'/RELEASE_SHA', 'x');

        $result = $manifest->verifyTree($this->root, ['bootstrap/cache', 'RELEASE_SHA']);

        $this->assertSame(['index.php'], $result['modified']);
        $this->assertSame(['gone.txt'], $result['missing']);
        $this->assertSame(['app/shell.php'], $result['unlisted']);
        $this->assertSame([], $result['links']);
    }

    public function test_verify_tree_never_follows_a_symlink_and_reports_one_outside_the_allowed_places(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks.');
        }

        mkdir($this->root.'/elsewhere');
        file_put_contents($this->root.'/elsewhere/secret', 'x');
        symlink($this->root.'/elsewhere', $this->root.'/storage');
        symlink($this->root.'/elsewhere', $this->root.'/public_link');

        $result = $this->manifest([])->verifyTree($this->root, ['storage', 'elsewhere']);

        $this->assertSame(['public_link'], $result['links']);
        $this->assertSame([], $result['unlisted'], 'nothing behind a link is walked');
    }

    /**
     * @param  array<string, array{sha: string, size: int}>  $files
     */
    protected function manifest(array $files): Manifest
    {
        return new Manifest(
            'v0.6.0', 'production', str_repeat('c', 40), 'refs/heads/production', '2026-10-19T00:00:00Z', '8.4.24',
            'release.tar.gz', str_repeat('d', 64), 10, 20, 3,
            array_map(fn (array $f) => [$f['sha'], $f['size']], $files),
            ['bootstrap/cache/config.php'],
        );
    }
}
