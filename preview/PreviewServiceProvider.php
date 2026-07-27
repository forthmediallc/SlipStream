<?php

namespace Slipstream\Preview;

use Illuminate\Support\ServiceProvider;

/**
 * Preview-only. Testbench forces the app's default connection to an in-memory
 * SQLite DB, which is empty for each `serve` HTTP request. Repoint it at the
 * persistent preview.sqlite file (the one `testbench migrate --seed` populated)
 * so the served dashboard actually has data. Not part of the shipped package.
 */
class PreviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $database = dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'preview.sqlite';

        config([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
    }
}
