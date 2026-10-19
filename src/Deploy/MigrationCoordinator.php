<?php

namespace Slipstream\Deploy;

use Closure;
use RuntimeException;
use Slipstream\Fleet\SharedStore;
use Slipstream\Models\Server;
use Slipstream\Slipstream;
use Slipstream\Support\AuditLog;

/**
 * The migrate step: run the configured command exactly once per commit
 * across every node that shares the lock store and the app's database, while
 * the rest wait.
 *
 * The lock decides who runs; a marker in the same store records the outcome
 * (running, done, failed) so a node that deploys the commit later skips it,
 * and a node that waited fails its own deploy if the run it waited on
 * failed. The marker is a signal, not the guarantee — the command must be
 * safe to run again (Laravel's migrate is: it only runs what is pending), so
 * a marker lost to eviction costs one no-op run, never a double migration.
 * A node that dies mid-run leaves its lock to expire; a waiter whose wait
 * outlasts the lock TTL takes the run over.
 */
class MigrationCoordinator
{
    public const OUTCOME_RAN = 'ran';

    public const OUTCOME_ALREADY = 'already';

    /** @var Closure(int): void */
    protected Closure $sleep;

    public function __construct(?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? fn (int $seconds) => sleep($seconds);
    }

    /**
     * The artisan command line the step runs, without a leading "php artisan";
     * null when no migrate step is configured.
     */
    public static function command(): ?string
    {
        $command = trim((string) config('slipstream.migrations.command', ''));
        $command = (string) preg_replace('#^(php\s+)?artisan\s+#', '', $command);

        return $command === '' ? null : $command;
    }

    /**
     * Whether this node may run migrations: SLIPSTREAM_RUN_MIGRATIONS when set;
     * else, in webhook mode, the server registry (on by default). In poll mode
     * a node's database holds no settings, so unset means yes — an old node
     * and a fresh one must agree.
     */
    public static function enabledHere(): bool
    {
        $configured = config('slipstream.migrations.enabled');

        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        if (Slipstream::pollMode()) {
            return true;
        }

        $server = Server::query()->where('server_name', Slipstream::serverName())->first();

        return $server === null || (bool) $server->run_migrations;
    }

    public function waitSeconds(): int
    {
        $configured = config('slipstream.migrations.wait_seconds');

        return is_numeric($configured)
            ? max(0, (int) $configured)
            : $this->lockTtl() + 60;
    }

    /**
     * @param  Closure(): void  $migrate  runs the command; throws on failure
     * @param  Closure(string): void  $say  one log line
     * @return self::OUTCOME_*
     *
     * @throws RuntimeException  the run failed here, failed on the node this one
     *                           waited for, or the wait ran out
     */
    public function run(string $sha, Closure $migrate, Closure $say): string
    {
        $cache = SharedStore::cache();
        $sha = strtolower($sha);
        $short = substr($sha, 0, 7);
        $marker = self::markerKey($sha);
        $deadline = now()->addSeconds($this->waitSeconds());
        $waitedOn = null;

        while (true) {
            $lock = $cache->lock(self::lockKey($sha), $this->lockTtl());

            if ($lock->get()) {
                try {
                    $state = $cache->get($marker);

                    if (($state['status'] ?? null) === 'done') {
                        $say("Migrations for {$short} already ran on ".($state['node'] ?? 'another node').'.');

                        return self::OUTCOME_ALREADY;
                    }

                    if ($waitedOn !== null && ($state['status'] ?? null) === 'failed') {
                        throw $this->failedElsewhere($short, $state);
                    }

                    $cache->put($marker, $this->state('running'), $this->lockTtl());
                    $say("Running migrations for {$short} — this node holds the lock.");

                    try {
                        $migrate();
                    } catch (\Throwable $e) {
                        $cache->put($marker, $this->state('failed', $e->getMessage()), now()->addDays(30));
                        AuditLog::record('migrations.failed', ['sha' => $sha, 'error' => mb_substr($e->getMessage(), 0, 500)]);

                        throw $e;
                    }

                    $cache->put($marker, $this->state('done'), now()->addDays(30));
                    AuditLog::record('migrations.ran', ['sha' => $sha]);

                    return self::OUTCOME_RAN;
                } finally {
                    $lock->release();
                }
            }

            $state = $cache->get($marker);
            $status = $state['status'] ?? null;

            if ($status === 'done') {
                $say("Migrations for {$short} ran on ".($state['node'] ?? 'another node').' while this node waited.');

                return self::OUTCOME_ALREADY;
            }

            if ($status === 'failed' && $waitedOn !== null) {
                throw $this->failedElsewhere($short, $state);
            }

            if ($waitedOn === null) {
                $waitedOn = (string) ($state['node'] ?? 'another node');
                $say("Waiting for {$waitedOn} to finish migrations for {$short}…");
                AuditLog::record('migrations.waiting', ['sha' => $sha, 'on' => $waitedOn]);
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                throw new RuntimeException("Gave up after {$this->waitSeconds()}s waiting for {$waitedOn} to migrate {$short}.");
            }

            ($this->sleep)(5);
        }
    }

    /**
     * For a node set not to run migrations: hold its swap while another node
     * migrates this commit, and fail its deploy when the last run failed. A
     * run nobody has started is not waited for — nothing says one will be.
     *
     * @param  Closure(string): void  $say  one log line
     * @return 'done'|'none'
     *
     * @throws RuntimeException  the last run failed, or the wait ran out
     */
    public function awaitRun(string $sha, Closure $say): string
    {
        $cache = SharedStore::cache();
        $short = substr(strtolower($sha), 0, 7);
        $deadline = now()->addSeconds($this->waitSeconds());
        $waitedOn = null;

        while (true) {
            $state = $cache->get(self::markerKey($sha));
            $status = $state['status'] ?? null;

            if ($status === 'failed') {
                throw $this->failedElsewhere($short, $state);
            }

            if ($status !== 'running') {
                if ($waitedOn !== null) {
                    $say("Migrations for {$short} finished on {$waitedOn}.");
                }

                return $status === 'done' ? 'done' : 'none';
            }

            if ($waitedOn === null) {
                $waitedOn = (string) ($state['node'] ?? 'another node');
                $say("Waiting for {$waitedOn} to finish migrations for {$short}…");
                AuditLog::record('migrations.waiting', ['sha' => strtolower($sha), 'on' => $waitedOn]);
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                throw new RuntimeException("Gave up after {$this->waitSeconds()}s waiting for {$waitedOn} to migrate {$short}.");
            }

            ($this->sleep)(5);
        }
    }

    /**
     * Where a commit's marker and lock live: scoped to the command and the
     * app's database as well as the commit, so a fleet running another
     * command, or another environment sharing the cache server, keeps its own.
     */
    public static function markerKey(string $sha): string
    {
        return 'slipstream:migrate:'.self::subject($sha);
    }

    public static function lockKey(string $sha): string
    {
        return 'slipstream:migrate-lock:'.self::subject($sha);
    }

    protected static function subject(string $sha): string
    {
        return strtolower($sha).':'.substr(sha1((self::command() ?? '').'|'.SharedStore::scope()), 0, 12);
    }

    protected function lockTtl(): int
    {
        return max(60, (int) config('slipstream.deploy.migration_lock_ttl', 900));
    }

    /**
     * @return array{status: string, node: string, at: string, error?: string}
     */
    protected function state(string $status, ?string $error = null): array
    {
        return array_filter([
            'status' => $status,
            'node' => Slipstream::serverName(),
            'at' => now()->toIso8601String(),
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function failedElsewhere(string $short, array $state): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Migrations for %s failed on %s: %s',
            $short,
            $state['node'] ?? 'another node',
            $state['error'] ?? 'no detail recorded'
        ));
    }
}
