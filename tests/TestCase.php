<?php

namespace Slipstream\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Slipstream\Slipstream;
use Slipstream\SlipstreamServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Static auth/health registries leak across tests otherwise.
        Slipstream::$authUsing = null;
        Slipstream::$healthChecks = [];
        Slipstream::$nodeName = null;
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SlipstreamServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        // Array store supports atomic locks within the process (fine for tests).
        $config->set('cache.default', 'array');
        $config->set('slipstream.server_name', 'test-node');
        // Keep detached deploys/relays from actually spawning during tests.
        $config->set('slipstream.logging.file_log', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Recursively delete a temp tree, symlink- and dotfile-safe.
     *
     * One shared implementation on purpose: the per-test copies this replaced
     * had diverged — one followed symlinks (a teardown walking `current ->`
     * would delete the target's contents), most missed dotfiles (leaking .env
     * and .git into the system temp dir every run), and one leaned on
     * GLOB_BRACE, which PHP only defines where libc's glob has it (not musl).
     */
    protected function removeTree(string $path): void
    {
        if (is_link($path)) {
            // A link to a directory needs rmdir on Windows, unlink elsewhere.
            @unlink($path) || @rmdir($path);

            return;
        }

        if (is_file($path)) {
            @chmod($path, 0666);
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        // Tests deliberately create read-only directories (0555, 0000) to
        // exercise permission checks; teardown must still be able to empty them.
        @chmod($path, 0777);

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path.DIRECTORY_SEPARATOR.$name);
            }
        }

        @rmdir($path);
    }
}
