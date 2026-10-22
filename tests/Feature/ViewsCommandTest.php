<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Support\Facades\View;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Slipstream\Tests\TestCase;

/**
 * Compiled views are code the app runs. In artifact mode they compile into
 * the release, and slipstream:views compiles every one of them by the path
 * Laravel renders it from, so nothing compiles after the deploy has recorded
 * the hashes: a compiled view that appears later is drift.
 */
class ViewsCommandTest extends TestCase
{
    protected string $root;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-views-'.bin2hex(random_bytes(4));
        mkdir($this->root.DIRECTORY_SEPARATOR.'compiled', 0777, true);
        $app['config']->set('view.compiled', $this->root.DIRECTORY_SEPARATOR.'compiled');
    }

    protected function artifactMode($app): void
    {
        $app['config']->set('slipstream.release.source', 'artifact');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_nothing_compiles_on_first_use_after_it_has_run(): void
    {
        // A package that registers its views as most do, by a path with
        // "/../" in it: view:cache compiles them by their real path, and
        // Laravel renders them by this one.
        $views = $this->root.'/package/resources/views';
        mkdir($this->root.'/package/src', 0777, true);
        mkdir($views.'/emails', 0777, true);
        file_put_contents($views.'/frame.blade.php', '<form>@once<script src="/pay.js"></script>@endonce{{ $title }}</form>');
        file_put_contents($views.'/emails/receipt.blade.php', '<p>{{ $total }}</p>');
        touch($views.'/frame.blade.php', time() - 60);
        touch($views.'/emails/receipt.blade.php', time() - 60);
        View::addNamespace('vault', $this->root.'/package/src/../resources/views');

        $this->artisan('slipstream:views')->assertExitCode(0);
        $before = $this->compiled();
        $this->assertNotSame([], $before);

        // What a live node renders after the swap: the package's views, an
        // error page, and the health page load balancers poll.
        $this->assertStringContainsString('Pay', view('vault::frame', ['title' => 'Pay'])->render());
        view('vault::emails.receipt', ['total' => '1.00'])->render();
        view('errors::404')->render();
        View::file(dirname((string) (new \ReflectionClass(ApplicationBuilder::class))->getFileName()).'/../resources/health-up.blade.php', ['exception' => null])->render();

        $this->assertSame($before, $this->compiled(), 'a view compiled after the record reads as drift');
        $this->artisan('slipstream:views')->expectsOutputToContain('Compiled 0 view(s)')->assertExitCode(0);
    }

    #[DefineEnvironment('artifactMode')]
    public function test_in_artifact_mode_views_compile_into_the_release_and_stay_compiled(): void
    {
        $views = base_path('bootstrap/cache/views');

        $this->assertSame($views, config('view.compiled'));
        $this->assertFalse(config('view.check_cache_timestamps'));
        $this->assertStringStartsWith($views, app('blade.compiler')->getCompiledPath(base_path('resources/views/x.blade.php')));
    }

    public function test_outside_artifact_mode_laravel_keeps_its_own_settings(): void
    {
        $this->assertSame($this->root.DIRECTORY_SEPARATOR.'compiled', config('view.compiled'));
        $this->assertNotFalse(config('view.check_cache_timestamps', true));
    }

    /**
     * @return array<string, string>  name => hash
     */
    protected function compiled(): array
    {
        $files = [];
        foreach (glob($this->root.'/compiled/*') ?: [] as $file) {
            $files[basename($file)] = (string) hash_file('sha256', $file);
        }
        ksort($files);

        return $files;
    }
}
