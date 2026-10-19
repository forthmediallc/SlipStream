<?php

namespace Slipstream\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Slipstream\Slipstream;

/**
 * Slipstream's own tables as one unit: which connection holds them, creating
 * them on another, copying every row across, and dropping a set left behind.
 */
class Tables
{
    /**
     * Every Slipstream table, parents before children. Copying in this order
     * satisfies the foreign keys (deployments -> pipelines; events and
     * deliveries -> deployments); dropping walks it backwards.
     *
     * @var list<string>
     */
    public const ALL = [
        'slipstream_settings',
        'slipstream_servers',
        'slipstream_pipelines',
        'slipstream_deployment_hooks',
        'slipstream_deployments',
        'slipstream_deployment_events',
        'slipstream_webhook_deliveries',
        'slipstream_activity_log',
        'slipstream_env_audits',
        'slipstream_env_snapshots',
        'slipstream_env_pushes',
        'slipstream_backups',
    ];

    /**
     * The connection Slipstream reads and writes right now, by name.
     */
    public function activeConnection(): string
    {
        return Slipstream::databaseConnection() ?? DB::getDefaultConnection();
    }

    public function defaultConnection(): string
    {
        return DB::getDefaultConnection();
    }

    public function defined(string $connection): bool
    {
        return is_array(config("database.connections.{$connection}"));
    }

    /**
     * Whether Slipstream's tables live apart from the app's database. Then the
     * app's migrations table — shared across nodes, and the only record the
     * migrator keeps — says nothing about this node's Slipstream schema.
     */
    public function hasOwnConnection(): bool
    {
        return ! $this->sameDatabase($this->activeConnection(), $this->defaultConnection());
    }

    /**
     * Bring Slipstream's tables on a connection up to this package's
     * migrations. Cheap when already current: a signature of the migration
     * files is kept on that connection and compared first. Every migration is
     * guarded, so running them again over a current schema changes nothing.
     * Returns whether the migrations ran.
     */
    public function ensureCurrent(string $connection): bool
    {
        $signature = $this->migrationsSignature();

        try {
            $stored = Schema::connection($connection)->hasTable('slipstream_settings')
                ? DB::connection($connection)->table('slipstream_settings')->where('key', self::SCHEMA_KEY)->value('value')
                : null;
        } catch (\Throwable) {
            $stored = null;
        }

        if ($stored === $signature) {
            return false;
        }

        $this->createOn($connection);

        DB::connection($connection)->table('slipstream_settings')->updateOrInsert(
            ['key' => self::SCHEMA_KEY],
            ['value' => $signature, 'encrypted' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        return true;
    }

    /**
     * ensureCurrent() for Slipstream's own connection, when it has one. A node
     * on the app's default connection leaves its schema to `artisan migrate`,
     * as before.
     */
    public function ensureCurrentIfOwnConnection(): bool
    {
        return $this->hasOwnConnection() && $this->ensureCurrent($this->activeConnection());
    }

    public const SCHEMA_KEY = 'schema_migrations';

    protected function migrationsSignature(): string
    {
        return sha1(implode(',', array_map('basename', $this->migrationFiles())));
    }

    public function driver(string $connection): string
    {
        return (string) config("database.connections.{$connection}.driver", '?');
    }

    /**
     * Where a connection points, so two names for one database read as one.
     * Every in-memory SQLite connection is its own database.
     */
    public function identity(string $connection): string
    {
        $config = (array) config("database.connections.{$connection}", []);
        $driver = (string) ($config['driver'] ?? '');

        if ($driver === 'sqlite') {
            $path = (string) ($config['database'] ?? '');

            if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory')) {
                return "sqlite::memory:#{$connection}";
            }

            return 'sqlite:'.str_replace('\\', '/', realpath($path) ?: $path);
        }

        $host = $config['write']['host'] ?? $config['host'] ?? '';

        return implode('|', [
            $driver,
            (string) ($config['url'] ?? ''),
            is_array($host) ? implode(',', $host) : (string) $host,
            (string) ($config['port'] ?? ''),
            (string) ($config['database'] ?? ''),
        ]);
    }

    public function sameDatabase(string $a, string $b): bool
    {
        return $a === $b || $this->identity($a) === $this->identity($b);
    }

    /**
     * Human-readable target, never with credentials: driver plus the file,
     * or the host and database name.
     */
    public function describe(string $connection): string
    {
        $config = (array) config("database.connections.{$connection}", []);
        $driver = (string) ($config['driver'] ?? '?');

        if ($driver === 'sqlite') {
            return 'sqlite: '.($config['database'] ?? '?');
        }

        $host = $config['write']['host'] ?? $config['host'] ?? null;
        $host = is_array($host) ? implode(',', $host) : $host;

        return $driver.': '.trim(($host ? $host.'/' : '').($config['database'] ?? ''), '/');
    }

    /**
     * @return list<string> Slipstream tables present on the connection
     */
    public function presentOn(string $connection): array
    {
        $schema = Schema::connection($connection);

        return array_values(array_filter(self::ALL, fn (string $table) => $schema->hasTable($table)));
    }

    /**
     * @return array<string, int> rows per Slipstream table on the connection
     */
    public function counts(string $connection): array
    {
        $counts = [];

        foreach ($this->presentOn($connection) as $table) {
            $counts[$table] = DB::connection($connection)->table($table)->count();
        }

        return $counts;
    }

    /**
     * Newest id per Slipstream table (null for an empty table). The prune
     * deletes old rows and never the newest, so "the live set's newest id has
     * reached the old set's" survives days of pruning where a row count would
     * not.
     *
     * @return array<string, int|null>
     */
    public function maxIds(string $connection): array
    {
        $ids = [];

        foreach ($this->presentOn($connection) as $table) {
            $max = DB::connection($connection)->table($table)->max('id');
            $ids[$table] = $max === null ? null : (int) $max;
        }

        return $ids;
    }

    /**
     * Create the schema on a connection by running the package migrations
     * against it directly. The migrator is bypassed on purpose: its record of
     * what ran lives on the app's default connection and already says "done"
     * on an existing install. Safe to repeat — the creates are guarded.
     */
    public function createOn(string $connection): void
    {
        $this->ensureSqliteFile($connection);

        $db = app('db');
        $previous = $db->getDefaultConnection();

        try {
            $db->setDefaultConnection($connection);

            foreach ($this->migrationFiles() as $file) {
                (require $file)->up();
            }
        } finally {
            $db->setDefaultConnection($previous);
        }
    }

    /**
     * Copy every row of every Slipstream table, ids included, inside one
     * transaction on the target so a failure leaves it as it was.
     *
     * @param  (callable(string, int): void)|null  $progress  called per table with the rows copied
     * @return array<string, int> rows copied per table
     */
    public function copy(string $from, string $to, ?callable $progress = null): array
    {
        /** @var Connection $source */
        $source = DB::connection($from);
        /** @var Connection $target */
        $target = DB::connection($to);

        $copied = [];

        $target->transaction(function () use ($source, $target, $from, $to, $progress, &$copied) {
            foreach ($this->presentOn($from) as $table) {
                if (! Schema::connection($to)->hasTable($table)) {
                    throw new RuntimeException("{$table} does not exist on {$to}.");
                }

                $columns = array_values(array_intersect(
                    Schema::connection($from)->getColumnListing($table),
                    Schema::connection($to)->getColumnListing($table),
                ));

                // Stay under the smallest bound-parameter limit around (999 on
                // older SQLite builds, 2100 on SQL Server).
                $batch = max(1, intdiv(999, max(1, count($columns))));
                $count = 0;

                $this->withExplicitIds($target, $table, function () use ($source, $target, $table, $columns, $batch, &$count) {
                    $source->table($table)->select($columns)->chunkById($batch, function ($rows) use ($target, $table, &$count) {
                        $target->table($table)->insert($rows->map(fn ($row) => (array) $row)->all());
                        $count += $rows->count();
                    }, 'id');
                });

                $this->resetSequence($target, $table);
                $copied[$table] = $count;

                if ($progress) {
                    $progress($table, $count);
                }
            }
        });

        return $copied;
    }

    /**
     * Drop every Slipstream table from a connection, children first.
     */
    public function dropFrom(string $connection): void
    {
        $schema = Schema::connection($connection);

        foreach (array_reverse(self::ALL) as $table) {
            $schema->dropIfExists($table);
        }
    }

    /**
     * Laravel's SQLite connector refuses a database file that does not exist
     * yet, and `migrate` only offers to create one for its own connection.
     * Created owner-writable only: it holds the encrypted webhook secret and
     * raw hook output.
     */
    public function ensureSqliteFile(string $connection): void
    {
        $config = (array) config("database.connections.{$connection}", []);

        if (($config['driver'] ?? null) !== 'sqlite') {
            return;
        }

        $path = (string) ($config['database'] ?? '');
        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory') || is_file($path)) {
            return;
        }

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir} for the SQLite database.");
        }

        if (! @touch($path)) {
            throw new RuntimeException("Cannot create the SQLite database at {$path}.");
        }

        @chmod($path, 0640);
    }

    /**
     * @return list<string>
     */
    protected function migrationFiles(): array
    {
        $files = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
        sort($files);

        return $files;
    }

    protected function withExplicitIds(Connection $target, string $table, callable $copy): void
    {
        $sqlsrv = $target->getDriverName() === 'sqlsrv';
        $name = $target->getTablePrefix().$table;

        if ($sqlsrv) {
            $target->unprepared("SET IDENTITY_INSERT [{$name}] ON");
        }

        try {
            $copy();
        } finally {
            if ($sqlsrv) {
                $target->unprepared("SET IDENTITY_INSERT [{$name}] OFF");
            }
        }
    }

    /**
     * Rows inserted with explicit ids do not advance a Postgres sequence;
     * without this the next insert would collide with a copied id. MySQL and
     * SQLite move their counters on their own.
     */
    protected function resetSequence(Connection $target, string $table): void
    {
        if ($target->getDriverName() !== 'pgsql') {
            return;
        }

        $name = $target->getTablePrefix().$table;

        $target->statement(
            "SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM \"{$name}\"), 1), (SELECT MAX(id) FROM \"{$name}\") IS NOT NULL)",
            [$name]
        );
    }
}
