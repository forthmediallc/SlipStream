<?php

namespace Slipstream\Database;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Slipstream\Slipstream;

/**
 * Base for Slipstream's migrations: they run on Slipstream's connection
 * (SLIPSTREAM_DB_CONNECTION, else the app's default). Laravel's migrator makes
 * a migration's connection the default while its up()/down() run, so the
 * Schema facade inside them follows without further changes.
 *
 * The record that a migration ran stays in the app's migrations table, as it
 * does for Telescope and Pulse. That split is why creates are guarded: when
 * the record is reset (migrate:fresh on the app database) but the tables live
 * elsewhere, running the migration again must not trip over them.
 */
abstract class SlipstreamMigration extends Migration
{
    public function getConnection()
    {
        return $this->connection ?? Slipstream::databaseConnection();
    }

    protected function createIfMissing(string $table, Closure $blueprint): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $blueprint);
        }
    }
}
