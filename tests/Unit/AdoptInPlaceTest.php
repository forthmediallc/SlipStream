<?php

namespace Slipstream\Tests\Unit;

use RuntimeException;
use Slipstream\Deploy\AdoptInPlace;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

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

    /**
     * The common first-install path: provision, deploy, deploy fails, come back
     * to adopt. The kept `<release>.failed` directory must not look like a
     * layout that is already in use.
     */
    public function test_a_release_kept_from_a_failed_deploy_does_not_block_adoption(): void
    {
        $this->checkout();
        mkdir($this->root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'20260101_000000_abc1234.failed', 0777, true);

        $adopter = new AdoptInPlace($this->root);

        $this->assertTrue($adopter->isPlainCheckout());
    }

    public function test_it_explains_why_it_declined(): void
    {
        $this->checkout();
        mkdir($this->root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'20260101_000000_abc1234', 0777, true);

        $this->assertStringContainsString('already in use', (new AdoptInPlace($this->root))->whyNotAdoptable());
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

    /**
     * Configured shared paths must end up as links into the layout root. Left
     * as the real directories the copy produced, everything written through
     * them after the document-root flip would stop being served by the next
     * deploy (which overlays shared/ from git content) and would be deleted
     * when the adopted release ages out.
     */
    public function test_it_moves_configured_shared_paths_out_of_the_release_and_links_them(): void
    {
        $this->requireSymlinks();
        $this->checkout();
        $this->file('public/images/patient/scan-1.jpg', 'jpeg bytes');

        $adopter = new AdoptInPlace($this->root, ['public/images/patient' => 'shared/images/patient']);
        $release = $adopter->adopt();

        $shared = $this->root.DIRECTORY_SEPARATOR.'shared'.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'patient';

        $this->assertDirectoryExists($shared);
        $this->assertFileExists($shared.DIRECTORY_SEPARATOR.'scan-1.jpg', 'live uploads seed the shared directory');

        $this->assertTrue(is_link($release.'/public/images/patient'), 'the release must link, not hold, shared data');
        $this->assertSame(realpath($shared), realpath($release.'/public/images/patient'));

        // Reachable through the new document root, and a write through it lands
        // in shared where the next deploy will still find it.
        $this->assertFileExists($this->root.'/current/public/images/patient/scan-1.jpg');
        file_put_contents($this->root.'/current/public/images/patient/scan-2.jpg', 'more bytes');
        $this->assertFileExists($shared.DIRECTORY_SEPARATOR.'scan-2.jpg');
    }

    /**
     * The OLD document root keeps serving until the operator flips nginx —
     * provision explicitly invites postponing that. If its shared paths stay
     * real directories, every upload taken in that window lands where nothing
     * links and disappears from the site at the flip. Both roots must write
     * through to the same shared directory from the moment of adoption.
     */
    public function test_the_old_document_root_writes_through_to_shared_after_adoption(): void
    {
        $this->requireSymlinks();
        $this->checkout();
        $this->file('public/images/patient/scan-1.jpg', 'jpeg bytes');

        (new AdoptInPlace($this->root, ['public/images/patient' => 'shared/images/patient']))->adopt();

        $old = $this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'patient';
        $shared = $this->root.DIRECTORY_SEPARATOR.'shared'.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'patient';

        $this->assertTrue(is_link($old), 'the live path must become a link into shared');
        $this->assertSame(realpath($shared), realpath($old));

        // An upload through the still-serving old root...
        file_put_contents($old.DIRECTORY_SEPARATOR.'uploaded-before-flip.jpg', 'between adopt and flip');

        // ...is already where the new root serves from.
        $this->assertFileExists($this->root.'/current/public/images/patient/uploaded-before-flip.jpg');
        $this->assertFileExists($shared.DIRECTORY_SEPARATOR.'uploaded-before-flip.jpg');
    }

    public function test_adopting_requires_the_shared_env(): void
    {
        $this->checkout();
        unlink($this->root.DIRECTORY_SEPARATOR.'.env');

        $adopter = new AdoptInPlace($this->root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\.env/');
        $adopter->adopt();
    }

    public function test_shared_paths_that_already_exist_are_filled_in_not_overwritten(): void
    {
        $this->requireSymlinks();
        $this->checkout();
        $this->file('public/images/patient/from-checkout.jpg', 'checkout');
        $this->file('shared/images/patient/already-shared.jpg', 'shared');

        $release = (new AdoptInPlace($this->root, ['public/images/patient' => 'shared/images/patient']))->adopt();

        $shared = $this->root.DIRECTORY_SEPARATOR.'shared'.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'patient';

        $this->assertFileExists($shared.DIRECTORY_SEPARATOR.'already-shared.jpg');
        $this->assertFileExists($shared.DIRECTORY_SEPARATOR.'from-checkout.jpg');
        $this->assertTrue(is_link($release.'/public/images/patient'));
        $this->assertTrue(
            is_link($this->root.'/public/images/patient'),
            'the old root converges on shared in the merge case too'
        );
    }

    /**
     * The layout's shared/ tree sits next to releases/, so a naive recursive
     * copy pulls every upload the site has ever taken into the release — bytes
     * that are never read (the release links to shared) and are deleted again
     * when the release ages out of keep-N.
     */
    public function test_it_does_not_copy_the_layout_shared_tree_into_the_release(): void
    {
        $this->requireSymlinks();
        $this->checkout();
        $this->file('public/images/patient/live.jpg', 'live');
        $this->file('shared/images/patient/already-shared.jpg', str_repeat('S', 2048));

        $release = (new AdoptInPlace($this->root, ['public/images/patient' => 'shared/images/patient']))->adopt();

        $this->assertFileDoesNotExist(
            $release.'/shared/images/patient/already-shared.jpg',
            'the adopted release must not hold a second copy of the shared tree'
        );
        $this->assertTrue(is_link($release.'/public/images/patient'));
    }

    /**
     * A shared destination is allowed to live inside a directory the release
     * needs; skipping it must not take the whole parent with it.
     */
    public function test_excluding_a_shared_destination_does_not_empty_its_parent(): void
    {
        $this->requireSymlinks();
        $this->checkout();
        $this->file('public/uploads/photo.jpg', 'photo');

        $release = (new AdoptInPlace($this->root, ['public/uploads' => 'public/uploads']))->adopt();

        $this->assertFileExists($release.'/public/index.php', 'public/ must survive');
        $this->assertFileExists($release.'/public/build/app.css');
        $this->assertTrue(is_link($release.'/public/uploads'));
    }

    /**
     * Adopting promises a release byte-identical to what is serving. A subtree
     * the copy cannot read must abort the adopt, not arrive as a silently empty
     * directory that only surfaces after the document root is flipped.
     */
    public function test_an_unreadable_subtree_aborts_the_adopt_instead_of_being_skipped(): void
    {
        $this->requireSymlinks();

        if (DIRECTORY_SEPARATOR === '\\' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('needs POSIX permissions and a non-root user.');
        }

        $this->checkout();
        $this->file('app/Secret/Critical.php', '<?php // load-bearing');
        chmod($this->root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Secret', 0000);

        $adopter = new AdoptInPlace($this->root);
        $thrown = null;

        try {
            $adopter->adopt();
        } catch (RuntimeException $e) {
            $thrown = $e;
        } finally {
            chmod($this->root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Secret', 0755);
        }

        $this->assertNotNull($thrown, 'adopt() must abort rather than produce a partial release');
        $this->assertStringContainsString('Cannot read', $thrown->getMessage());

        // The half-copied tree must not survive to block the retry.
        $this->assertSame([], $adopter->existingReleases());
        $this->assertTrue($adopter->isPlainCheckout(), 'a failed adopt must stay retryable');
    }

    /**
     * A shared path that cannot be moved into place must fail loudly: left
     * unchecked it produces a dangling link that 404s every upload the moment
     * the document root is flipped.
     */
    public function test_a_failed_shared_path_move_throws_rather_than_dangling(): void
    {
        $this->requireSymlinks();

        if (DIRECTORY_SEPARATOR === '\\' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('needs POSIX permissions and a non-root user.');
        }

        $this->checkout();
        $this->file('public/images/patient/live.jpg', 'live');
        mkdir($this->root.DIRECTORY_SEPARATOR.'shared', 0555, true);

        $adopter = new AdoptInPlace($this->root, ['public/images/patient' => 'shared/images/patient']);
        $thrown = null;

        try {
            $adopter->adopt();
        } catch (RuntimeException $e) {
            $thrown = $e;
        } finally {
            chmod($this->root.DIRECTORY_SEPARATOR.'shared', 0755);
        }

        $this->assertNotNull($thrown, 'a shared path that cannot be moved must abort the adopt');

        // The live data is still where the site is serving it from.
        $this->assertFileExists($this->root.'/public/images/patient/live.jpg');
        $this->assertSame([], $adopter->existingReleases());
    }

    public function test_a_checkout_without_git_falls_back_to_an_adopted_release_name(): void
    {
        $this->requireSymlinks();
        $this->checkout();

        $release = (new AdoptInPlace($this->root))->adopt();

        // No git repo here, so the fallback name is used and no SHA is recorded.
        $this->assertStringEndsWith('_adopted', $release);
        $this->assertFileDoesNotExist($release.'/RELEASE_SHA');
    }

    public function test_it_names_the_release_after_head_when_the_checkout_is_a_git_repo(): void
    {
        $this->requireSymlinks();
        $this->checkout();

        if (! $this->gitAvailable()) {
            $this->markTestSkipped('git is not available.');
        }

        foreach ([['init', '-q'], ['config', 'user.email', 't@example.com'], ['config', 'user.name', 'T'],
            ['add', '-A'], ['commit', '-qm', 'initial']] as $args) {
            (new Process(['git', ...$args], $this->root))->mustRun();
        }

        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->root))->mustRun()->getOutput());

        $release = (new AdoptInPlace($this->root))->adopt();

        $this->assertStringEndsWith('_'.substr($head, 0, 7), $release);
        $this->assertSame($head, trim((string) file_get_contents($release.'/RELEASE_SHA')));
    }

    protected function gitAvailable(): bool
    {
        $probe = new Process(DIRECTORY_SEPARATOR === '\\' ? ['where', 'git'] : ['which', 'git']);
        $probe->run();

        return $probe->isSuccessful();
    }
}
