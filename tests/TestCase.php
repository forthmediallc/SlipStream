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
}
