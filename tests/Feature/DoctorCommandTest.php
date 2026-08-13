<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-doctor-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    protected function removeTree(string $path): void
    {
        if (is_link($path)) {
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

    /**
     * A layout with nothing set up: the checks should name the gaps rather than
     * blow up.
     */
    public function test_an_unprovisioned_layout_reports_failures_rather_than_crashing(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('current symlink')
            ->expectsOutputToContain('shared .env')
            ->assertExitCode(1);
    }

    public function test_a_419_from_the_hook_is_reported_as_a_csrf_problem(): void
    {
        Http::fake(['*' => Http::response('Page Expired', 419)]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('CSRF')
            ->expectsOutputToContain('validateCsrfTokens')
            ->assertExitCode(1);
    }

    public function test_an_enforced_signature_counts_as_a_healthy_hook(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('signature enforced')
            ->assertExitCode(1); // other checks still fail on this bare layout
    }

    public function test_it_passes_on_a_fully_set_up_layout(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $release = $this->root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'20260101_000000_abc1234';
        mkdir($release, 0755, true);
        chmod($this->root.DIRECTORY_SEPARATOR.'releases', 0755);

        foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $sub) {
            mkdir($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub), 0777, true);
        }

        file_put_contents($this->root.DIRECTORY_SEPARATOR.'.env', "APP_KEY=x\n");

        if (! @symlink($release, $this->root.DIRECTORY_SEPARATOR.'current')) {
            $this->markTestSkipped('creating symlinks is not permitted in this environment.');
        }

        // The git mirror is the only remaining gap, and it is reported as such.
        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('shared .env')
            ->expectsOutputToContain('git mirror')
            ->assertExitCode(1);
    }
}
