<?php

namespace Slipstream\Tests\Feature;

use Closure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Slipstream\Deploy\MigrationCoordinator;
use Slipstream\Models\Server;
use Slipstream\Tests\TestCase;

/**
 * Migrations once per commit across independent nodes. "Another node" is
 * played by holding the lock and writing the marker the way it would; the
 * array store's locks are shared within the process, like a real shared store.
 */
class MigrationCoordinatorTest extends TestCase
{
    protected const SHA = 'dddddddddddddddddddddddddddddddddddddddd';

    public function test_the_first_node_runs_and_a_node_deploying_the_commit_later_skips(): void
    {
        $ran = 0;
        $lines = [];

        $first = $this->coordinator()->run(self::SHA, function () use (&$ran) {
            $ran++;
        }, function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        config()->set('slipstream.server_name', 'peer-2');
        $later = $this->coordinator()->run(self::SHA, function () use (&$ran) {
            $ran++;
        }, function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertSame([MigrationCoordinator::OUTCOME_RAN, MigrationCoordinator::OUTCOME_ALREADY], [$first, $later]);
        $this->assertSame(1, $ran);
        $this->assertStringContainsString('already ran on test-node', end($lines));
    }

    public function test_a_node_waits_for_the_run_in_progress_then_skips(): void
    {
        $other = $this->anotherNodeIsMigrating();

        $sleeps = 0;
        $outcome = $this->coordinator(function () use (&$sleeps, $other) {
            $sleeps++;
            $this->marker('done');
            $other->release();
        })->run(self::SHA, fn () => $this->fail('must not run while another node does'), fn () => null);

        $this->assertSame(MigrationCoordinator::OUTCOME_ALREADY, $outcome);
        $this->assertSame(1, $sleeps);
    }

    public function test_a_node_fails_its_deploy_when_the_run_it_waited_for_failed(): void
    {
        $other = $this->anotherNodeIsMigrating();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migrations for ddddddd failed on peer-1: SQLSTATE[42S01]');

        $this->coordinator(function () use ($other) {
            $this->marker('failed', 'SQLSTATE[42S01]: table already exists');
            $other->release();
        })->run(self::SHA, fn () => $this->fail('must not run'), fn () => null);
    }

    public function test_a_failure_here_is_recorded_for_the_nodes_still_waiting(): void
    {
        try {
            $this->coordinator()->run(self::SHA, fn () => throw new RuntimeException('boom'), fn () => null);
            $this->fail('the failure must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $state = Cache::get(MigrationCoordinator::markerKey(self::SHA));
        $this->assertSame('failed', $state['status']);
        $this->assertSame('boom', $state['error']);
        $this->assertSame('test-node', $state['node']);
    }

    public function test_a_fresh_attempt_after_a_failure_runs_again(): void
    {
        // A node retrying the commit later — not one that waited on the
        // failed run — takes the lock and tries itself.
        $this->marker('failed', 'connection refused');

        $ran = false;
        $outcome = $this->coordinator()->run(self::SHA, function () use (&$ran) {
            $ran = true;
        }, fn () => null);

        $this->assertSame(MigrationCoordinator::OUTCOME_RAN, $outcome);
        $this->assertTrue($ran);
        $this->assertSame('done', Cache::get(MigrationCoordinator::markerKey(self::SHA))['status']);
    }

    public function test_the_wait_is_bounded(): void
    {
        config()->set('slipstream.migrations.wait_seconds', 10);
        $this->freezeTime();
        $this->anotherNodeIsMigrating();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gave up after 10s waiting for peer-1 to migrate ddddddd');

        $this->coordinator(fn () => $this->travel(5)->seconds())
            ->run(self::SHA, fn () => $this->fail('must not run'), fn () => null);
    }

    public function test_a_crashed_run_is_taken_over_once_its_lock_expires(): void
    {
        $other = $this->anotherNodeIsMigrating();

        $ran = false;
        $outcome = $this->coordinator(fn () => $other->release()) // the dead node's lock expires
            ->run(self::SHA, function () use (&$ran) {
                $ran = true;
            }, fn () => null);

        $this->assertSame(MigrationCoordinator::OUTCOME_RAN, $outcome);
        $this->assertTrue($ran, 'the marker still said running; nobody else will finish it');
    }

    public function test_a_run_belongs_to_its_command_and_its_database_as_well_as_its_commit(): void
    {
        $this->coordinator()->run(self::SHA, fn () => null, fn () => null);

        // The vault fleet's own command on the same commit, through the same store.
        config()->set('slipstream.migrations.command', 'vault:migrate --force --if-vault');
        $ran = false;
        $outcome = $this->coordinator()->run(self::SHA, function () use (&$ran) {
            $ran = true;
        }, fn () => null);
        $this->assertSame([MigrationCoordinator::OUTCOME_RAN, true], [$outcome, $ran]);

        // Staging beside production on one cache server: another database.
        $production = MigrationCoordinator::markerKey(self::SHA);
        config()->set('database.connections.staging', ['driver' => 'mysql', 'host' => 'staging-db.internal', 'database' => 'app']);
        config()->set('database.default', 'staging');
        $staging = MigrationCoordinator::markerKey(self::SHA);
        config()->set('database.default', 'testing');

        $this->assertNotSame($production, $staging);
        $this->assertSame($production, MigrationCoordinator::markerKey(strtoupper(self::SHA)));
    }

    public function test_a_node_that_does_not_migrate_waits_for_a_run_in_progress(): void
    {
        $other = $this->anotherNodeIsMigrating();
        $lines = [];

        $outcome = $this->coordinator(function () use ($other) {
            $this->marker('done');
            $other->release();
        })->awaitRun(self::SHA, function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertSame('done', $outcome);
        $this->assertSame(['Waiting for peer-1 to finish migrations for ddddddd…', 'Migrations for ddddddd finished on peer-1.'], $lines);
    }

    public function test_a_node_that_does_not_migrate_fails_when_the_last_run_failed(): void
    {
        $this->marker('failed', 'SQLSTATE[HY000]: connection refused');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migrations for ddddddd failed on peer-1: SQLSTATE[HY000]');

        $this->coordinator()->awaitRun(self::SHA, fn () => null);
    }

    public function test_a_node_that_does_not_migrate_waits_for_nothing_nobody_started(): void
    {
        $outcome = $this->coordinator(fn () => $this->fail('nothing to wait for'))->awaitRun(self::SHA, fn () => null);

        $this->assertSame('none', $outcome);
    }

    public function test_the_command_and_eligibility_come_from_the_environment_first(): void
    {
        config()->set('slipstream.migrations.command', 'php artisan vault:migrate --force --if-vault');
        $this->assertSame('vault:migrate --force --if-vault', MigrationCoordinator::command());

        config()->set('slipstream.migrations.command', null);
        $this->assertNull(MigrationCoordinator::command());

        Server::query()->create(['server_name' => 'test-node', 'enabled' => true, 'paused' => false, 'run_migrations' => false]);
        $this->assertFalse(MigrationCoordinator::enabledHere(), 'the registry says no');

        config()->set('slipstream.migrations.enabled', 'true');
        $this->assertTrue(MigrationCoordinator::enabledHere(), 'SLIPSTREAM_RUN_MIGRATIONS wins when set');
    }

    protected function coordinator(?Closure $sleep = null): MigrationCoordinator
    {
        return new MigrationCoordinator($sleep ?? fn () => null);
    }

    protected function anotherNodeIsMigrating(): \Illuminate\Contracts\Cache\Lock
    {
        $lock = Cache::lock(MigrationCoordinator::lockKey(self::SHA), 900);
        $this->assertTrue($lock->get());
        $this->marker('running');

        return $lock;
    }

    protected function marker(string $status, ?string $error = null): void
    {
        Cache::put(MigrationCoordinator::markerKey(self::SHA), array_filter([
            'status' => $status,
            'node' => 'peer-1',
            'at' => now()->toIso8601String(),
            'error' => $error,
        ]), 900);
    }
}
