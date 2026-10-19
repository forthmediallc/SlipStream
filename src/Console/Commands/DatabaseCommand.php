<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Database\Tables;
use Slipstream\Models\ActivityLog;
use Slipstream\Models\Deployment;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;
use Slipstream\Support\AuditLog;

/**
 * Which database holds Slipstream's records, and moving them.
 *
 * The move is copy-first. The tables are created on the new connection and
 * every row copied while the app still runs on the old one; then
 * SLIPSTREAM_DB_CONNECTION is switched; then the old set is dropped. Nothing
 * is deleted until Slipstream reads the new connection and that connection
 * has caught up with the old one.
 */
class DatabaseCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:database
                            {action=status : status | copy | drop-source | ensure}
                            {--from= : Connection that holds the tables now (default: the app default connection)}
                            {--to= : Connection to copy them to (default: SLIPSTREAM_DB_CONNECTION)}
                            {--force : copy: go ahead with deployments in flight. drop-source: skip the completeness check and the prompt}
                            {--json : Machine-readable output (status)}';

    protected $description = 'Show which database holds Slipstream\'s records, and move them to another connection';

    public function handle(Tables $tables): int
    {
        try {
            return match ($this->argument('action')) {
                'status' => $this->status($tables),
                'copy' => $this->copy($tables),
                'drop-source' => $this->dropSource($tables),
                'ensure' => $this->ensure($tables),
                default => $this->unknownAction(),
            };
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Bring Slipstream's schema on its connection up to this release's
     * migrations. Deploys run it from inside each new release before it goes
     * live; it is also safe to run by hand.
     */
    protected function ensure(Tables $tables): int
    {
        $connection = $tables->activeConnection();

        try {
            $tables->ensureSqliteFile($connection);
            $ran = $tables->ensureCurrent($connection);
        } catch (\Throwable $e) {
            $this->components->error("Could not bring Slipstream's schema on {$connection} current: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($ran
            ? "Brought Slipstream's schema on {$connection} current."
            : "Slipstream's schema on {$connection} is current.");

        return self::SUCCESS;
    }

    protected function status(Tables $tables): int
    {
        $active = $tables->activeConnection();
        $default = $tables->defaultConnection();
        $separate = ! $tables->sameDatabase($active, $default);

        try {
            $counts = $tables->counts($active);
            $error = null;
        } catch (\Throwable $e) {
            $counts = null;
            $error = $e->getMessage();
        }

        $leftovers = null;
        if ($separate) {
            try {
                $leftovers = $tables->counts($default);
            } catch (\Throwable) {
                $leftovers = null;
            }
        }

        if ($this->option('json')) {
            $this->json([
                'connection' => $active,
                'configured' => Slipstream::databaseConnection(),
                'default' => $default,
                'separate' => $separate,
                'target' => $tables->describe($active),
                'error' => $error,
                'tables' => $counts,
                'left_on_default' => $leftovers,
            ]);

            return $error === null ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Slipstream uses', "{$active} ({$tables->describe($active)})".($separate ? '' : ' — the app database'));
        if ($separate) {
            $this->components->twoColumnDetail('App default', "{$default} ({$tables->describe($default)})");
        }

        if ($counts === null) {
            $this->components->error("Cannot reach {$active}: {$error}");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail(
            "Tables on {$active}",
            count($counts).'/'.count(Tables::ALL).' · '.number_format(array_sum($counts)).' rows'
        );

        if ($counts !== []) {
            $this->table(['Table', 'Rows'], array_map(
                fn (string $table, int $rows) => [$table, number_format($rows)],
                array_keys($counts),
                $counts
            ));
        }

        if ($counts === []) {
            $this->components->warn("No Slipstream tables on {$active}. Copy them from where they are now (slipstream:database copy --to={$active}), or run slipstream:install for a fresh set.");
        }

        if ($leftovers) {
            $this->components->warn(sprintf(
                '%d Slipstream tables (%s rows) are still on %s. Once you have checked %s: php artisan slipstream:database drop-source --from=%s',
                count($leftovers),
                number_format(array_sum($leftovers)),
                $default,
                $active,
                $default
            ));
        }

        if (! $separate && Slipstream::pciMode()) {
            $this->components->warn('PCI mode: these records live in the app database. Give Slipstream its own with SLIPSTREAM_DB_CONNECTION (README → PCI mode).');
        }

        return self::SUCCESS;
    }

    protected function copy(Tables $tables): int
    {
        $from = (string) ($this->option('from') ?: $tables->defaultConnection());
        $to = (string) ($this->option('to') ?: Slipstream::databaseConnection());

        if ($to === '') {
            throw new InvalidArgumentException('Name the target: --to=<connection> (one defined in config/database.php), or set SLIPSTREAM_DB_CONNECTION.');
        }

        foreach ([$from, $to] as $name) {
            if (! $tables->defined($name)) {
                throw new InvalidArgumentException("Connection \"{$name}\" is not defined in config/database.php.");
            }
        }

        if ($tables->sameDatabase($from, $to)) {
            throw new InvalidArgumentException("\"{$from}\" and \"{$to}\" are the same database. Nothing to move.");
        }

        if (! in_array('slipstream_settings', $tables->presentOn($from), true)) {
            throw new InvalidArgumentException("No Slipstream tables on \"{$from}\", so nothing to copy. Pass --from=<connection> if they are somewhere else.");
        }

        $inFlight = DB::connection($from)->table('slipstream_deployments')
            ->whereIn('status', [Deployment::STATUS_QUEUED, Deployment::STATUS_PENDING, Deployment::STATUS_BUILDING])
            ->count();
        if ($inFlight > 0 && ! $this->option('force')) {
            throw new InvalidArgumentException("{$inFlight} deployment(s) on \"{$from}\" are queued, pending or building, and what they write after the copy would stay behind. Let them finish, or pass --force.");
        }

        try {
            // A SQLite target is usually a file that does not exist yet.
            $tables->ensureSqliteFile($to);
            $existing = array_sum($tables->counts($to));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Cannot reach \"{$to}\": ".$e->getMessage());
        }
        if ($existing > 0) {
            throw new InvalidArgumentException("\"{$to}\" already holds ".number_format($existing).' Slipstream rows. Copy into an empty database, or drop the tables there first.');
        }

        $this->components->info("Copying Slipstream's tables from {$from} to {$to}…");
        $tables->createOn($to);

        $copied = $tables->copy($from, $to, fn (string $table, int $rows) => $this->components->twoColumnDetail($table, number_format($rows).' rows'));

        // The copy is one transaction on the target; this proves it landed.
        $landed = $tables->counts($to);
        foreach ($copied as $table => $rows) {
            if (($landed[$table] ?? -1) !== $rows) {
                throw new InvalidArgumentException("{$table} on \"{$to}\" holds ".($landed[$table] ?? 0)." rows after copying {$rows}. Drop the tables on \"{$to}\" and run the copy again.");
            }
        }

        $total = array_sum($copied);
        $this->recordCopy($from, $to, $total);
        $this->newLine();
        $this->components->info('Copied '.number_format($total)." rows into {$to}.");

        $grew = [];
        foreach ($tables->counts($from) as $table => $rows) {
            if ($rows > ($copied[$table] ?? 0)) {
                $grew[] = $table;
            }
        }
        if ($grew !== []) {
            $this->components->warn("Rows arrived on {$from} during the copy and are not on {$to}: ".implode(', ', $grew).'. To include them, drop the tables on '.$to.' and copy again between pushes.');
        }

        $configured = Slipstream::databaseConnection();
        if ($configured !== null && $tables->sameDatabase($configured, $to)) {
            $this->line("  Slipstream already reads {$to}. Once you have checked it: php artisan slipstream:database drop-source --from={$from}");

            return self::SUCCESS;
        }

        $this->line('  Next:');
        $this->line("  1. Set SLIPSTREAM_DB_CONNECTION={$to} in the shared .env.");
        $this->line('  2. Deploy, or run php artisan config:cache from current/ and reload PHP-FPM.');
        $this->line('  3. php artisan slipstream:database status   (check the counts)');
        $this->line("  4. php artisan slipstream:database drop-source --from={$from}");

        return self::SUCCESS;
    }

    protected function dropSource(Tables $tables): int
    {
        $from = (string) ($this->option('from') ?: $tables->defaultConnection());
        $active = $tables->activeConnection();

        if (! $tables->defined($from)) {
            throw new InvalidArgumentException("Connection \"{$from}\" is not defined in config/database.php.");
        }

        // Never overridable: this would delete the set Slipstream is using.
        if ($tables->sameDatabase($from, $active)) {
            throw new InvalidArgumentException("Slipstream reads \"{$active}\" right now, so those tables are the live set, not a leftover. Switch SLIPSTREAM_DB_CONNECTION to the new connection and re-cache config first.");
        }

        $source = $tables->counts($from);
        if ($source === []) {
            $this->components->info("No Slipstream tables on {$from}. Nothing to drop.");

            return self::SUCCESS;
        }

        $missing = $this->notCaughtUp($tables, $from, $active);
        if ($missing !== [] && ! $this->option('force')) {
            throw new InvalidArgumentException("\"{$active}\" has not caught up with \"{$from}\" in: ".implode(', ', $missing).'. Not dropping anything. Pass --force to drop regardless.');
        }

        $count = count($source);
        $rows = number_format(array_sum($source));
        if (! $this->option('force') && ! $this->confirm("Drop {$count} Slipstream tables from \"{$from}\"? That deletes {$rows} rows there; \"{$active}\" keeps its copy.")) {
            $this->line('Kept.');

            return self::SUCCESS;
        }

        $tables->dropFrom($from);

        Activity::log('database.source_dropped', ['from' => $from, 'tables' => $count, 'rows' => array_sum($source), 'via' => 'cli']);
        $this->components->info("Dropped {$count} Slipstream tables from {$from}.");
        $this->line("  {$from}'s migrations table still lists Slipstream's migrations. Leave those rows: they are how artisan migrate knows the schema is current.");

        return self::SUCCESS;
    }

    /**
     * Tables whose newest row on the old connection is not on the live one.
     *
     * @return list<string>
     */
    protected function notCaughtUp(Tables $tables, string $from, string $active): array
    {
        $live = $tables->maxIds($active);
        $missing = [];

        foreach ($tables->maxIds($from) as $table => $oldMax) {
            if (! array_key_exists($table, $live)) {
                $missing[] = "{$table} (absent)";
            } elseif ($oldMax !== null && ($live[$table] ?? 0) < $oldMax) {
                $missing[] = "{$table} (newest id ".($live[$table] ?? 'none')." vs {$oldMax})";
            }
        }

        return $missing;
    }

    /**
     * The move is recorded where the history now lives, not in the set that
     * is about to be dropped.
     */
    protected function recordCopy(string $from, string $to, int $rows): void
    {
        try {
            $entry = new ActivityLog([
                'user_id' => null,
                'action' => 'database.copied',
                'meta' => ['from' => $from, 'to' => $to, 'rows' => $rows, 'via' => 'cli'],
                'server_name' => Slipstream::serverName(),
            ]);
            $entry->setConnection($to);
            $entry->save();
        } catch (\Throwable) {
            // The copy stands even when it cannot be recorded.
        }

        AuditLog::record('database.copied', ['from' => $from, 'to' => $to, 'rows' => $rows]);
    }

    protected function unknownAction(): int
    {
        $this->components->error('Unknown action "'.$this->argument('action').'". Use: status, copy, drop-source, ensure.');

        return self::FAILURE;
    }
}
