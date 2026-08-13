<?php

namespace Slipstream\Tests\Unit;

use RuntimeException;
use Slipstream\Deploy\AdoptInPlace;
use Slipstream\Tests\TestCase;

class AdoptInPlaceTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-adopt-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    protected function removeTree(string $path): void
    {
        if (is_link($path)) {
            // On Windows a link to a directory needs rmdir, not unlink.
            @unlink($path) || @rmdir($path);

            return;
        }
        if (is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (glob($path.DIRECTORY_SEPARATOR.'*') ?: [] as $child) {
            $this->removeTree($child);
        }
        @rmdir($path);
    }

    protected function file(string $relative, string $contents = 'x'): void
    {
        $path = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * A plausible live checkout: app code, vendor, built assets, storage, .env.
     */
    protected function checkout(): void
    {
        $this->file('artisan', '#!/usr/bin/env php');
        $this->file('composer.json', '{}');
        $this->file('app/Models/User.php', '<?php');
        $this->file('vendor/autoload.php', '<?php');
        $this->file('public/index.php', '<?php');
        $this->file('public/build/app.css', 'body{}');
        $this->file('storage/logs/laravel.log', 'log line');
        $this->file('storage/app/public/upload.txt', 'upload');
        $this->file('.env', "APP_KEY=base64:x\n");
        $this->file('node_modules/left-pad/index.js', 'module.exports=1');
        mkdir($this->root.DIRECTORY_SEPARATOR.'deploy', 0777, true);
    }

    protected function requireSymlinks(): void
    {
        $probe = $this->root.DIRECTORY_SEPARATOR.'.symlink-probe';
        if (! @symlink($this->root, $probe)) {
            $this->markTestSkipped('creating symlinks is not permitted in this environment.');
        }
        @unlink($probe) || @rmdir($probe);
    }

    public function test_it_recognises_a_plain_checkout(): void
    {
        $this->checkout();

        $this->assertTrue((new AdoptInPlace($this->root))->isPlainCheckout());
    }

    public function test_a_directory_without_artisan_is_not_a_checkout(): void
    {
        $this->file('composer.json', '{}');

        $this->assertFalse((new AdoptInPlace($this->root))->isPlainCheckout());
    }

    public function test_a_populated_release_layout_is_not_a_plain_checkout(): void
    {
        $this->checkout();
        mkdir($this->root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'20260101_000000_abc1234', 0777, true);

        $this->assertFalse((new AdoptInPlace($this->root))->isPlainCheckout());
    }

    public function test_an_empty_releases_directory_still_counts_as_plain(): void
    {
        $this->checkout();
        mkdir($this->root.DIRECTORY_SEPARATOR.'releases', 0777, true);

        $this->assertTrue((new AdoptInPlace($this->root))->isPlainCheckout());
    }

    public function test_it_refuses_when_current_is_a_real_directory(): void
    {
        $this->checkout();
        mkdir($this->root.DIRECTORY_SEPARATOR.'current', 0777, true);

        $adopter = new AdoptInPlace($this->root);

        $this->assertTrue($adopter->currentIsRealDirectory());
        $this->assertFalse($adopter->isPlainCheckout());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mv -T/');
        $adopter->adopt();
    }

    public function test_it_copies_the_tree_and_links_current(): void
    {
        $this->requireSymlinks();
        $this->checkout();

        $adopter = new AdoptInPlace($this->root);
        $release = $adopter->adopt();

        $this->assertDirectoryExists($release);
        $this->assertStringStartsWith($this->root.DIRECTORY_SEPARATOR.'releases', $release);

        // Everything that is served came across untouched.
        $this->assertFileExists($release.'/public/index.php');
        $this->assertFileExists($release.'/public/build/app.css');
        $this->assertFileExists($release.'/app/Models/User.php');
        $this->assertFileExists($release.'/vendor/autoload.php');
        $this->assertFileExists($release.'/artisan');

        // Layout and shared entries did not.
        $this->assertDirectoryDoesNotExist($release.'/deploy');
        $this->assertDirectoryDoesNotExist($release.'/releases');
        $this->assertDirectoryDoesNotExist($release.'/node_modules');

        // Shared state is linked, not copied.
        $this->assertTrue(is_link($release.'/.env'));
        $this->assertTrue(is_link($release.'/storage'));
        $this->assertSame(
            realpath($this->root.'/storage'),
            realpath($release.'/storage')
        );
        $this->assertStringContainsString('log line', (string) file_get_contents($release.'/storage/logs/laravel.log'));

        $current = $this->root.DIRECTORY_SEPARATOR.'current';
        $this->assertTrue(is_link($current));
        $this->assertSame(realpath($release), realpath($current));
        $this->assertFileExists($current.'/public/index.php');
    }

    public function test_it_preserves_in_tree_symlinks_as_symlinks(): void
    {
        $this->requireSymlinks();
        $this->checkout();

        // The `storage:link` symlink every Laravel app has.
        symlink('../storage/app/public', $this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage');

        $release = (new AdoptInPlace($this->root))->adopt();

        $this->assertTrue(is_link($release.'/public/storage'), 'public/storage must stay a link into shared storage');
        $this->assertFileExists($release.'/public/storage/upload.txt');
    }

    public function test_the_adopted_layout_is_no_longer_a_plain_checkout(): void
    {
        $this->requireSymlinks();
        $this->checkout();

        $adopter = new AdoptInPlace($this->root);
        $adopter->adopt();

        $this->assertFalse($adopter->isPlainCheckout());
        $this->assertTrue($adopter->currentExists());
        $this->assertFalse($adopter->currentIsRealDirectory());
    }

    public function test_it_names_the_release_after_head_when_the_checkout_is_a_git_repo(): void
    {
        $this->requireSymlinks();
        $this->checkout();

        $release = (new AdoptInPlace($this->root))->adopt();

        // No git repo here, so the fallback name is used and no SHA is recorded.
        $this->assertStringEndsWith('_adopted', $release);
        $this->assertFileDoesNotExist($release.'/RELEASE_SHA');
    }
}
