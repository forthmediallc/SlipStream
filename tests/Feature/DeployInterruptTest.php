<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Console\Commands\DeployCommand;
use Slipstream\Models\Deployment;
use Slipstream\Tests\TestCase;

/**
 * Signal delivery itself is not portably testable, so this exercises the
 * handler body (markInterrupted) directly — the part with behaviour worth
 * pinning: which rows flip, and what happens to the half-built release.
 */
class DeployInterruptTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-interrupt-'.bin2hex(random_bytes(4));
        mkdir($this->root.DIRECTORY_SEPARATOR.'releases', 0777, true);
        config()->set('slipstream.app_root', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    protected function command(): DeployCommand
    {
        $command = new DeployCommand;
        $command->setLaravel($this->app);

        return $command;
    }

    protected function release(string $name): string
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$name;
        mkdir($path, 0777, true);

        return $path;
    }

    public function test_an_interrupt_fails_the_in_flight_row_and_preserves_its_release(): void
    {
        $release = $this->release('20260813_101500_abc1234');

        $building = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_BUILDING,
            'release_path' => $release,
        ]);

        $this->command()->markInterrupted(15);

        $fresh = $building->fresh();
        $this->assertSame(Deployment::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('signal 15', $fresh->failure_reason);
        $this->assertDirectoryDoesNotExist($release);
        $this->assertDirectoryExists($release.'.failed', 'the half-built tree must not pose as a deployable release');
    }

    /**
     * The trap is registered before Deployer::maybeCatchUp() chains a NEW row
     * in the same process. Targeting by server (the flock allows one in-flight
     * deploy per server) catches whichever row the signal actually killed.
     */
    public function test_a_chained_catch_up_row_is_covered_too(): void
    {
        $original = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'status' => Deployment::STATUS_DEPLOYED,
        ]);

        $catchUp = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_CATCH_UP,
            'status' => Deployment::STATUS_BUILDING,
        ]);

        $this->command()->markInterrupted(2);

        $this->assertSame(Deployment::STATUS_DEPLOYED, $original->fresh()->status, 'terminal rows are untouched');
        $this->assertSame(Deployment::STATUS_FAILED, $catchUp->fresh()->status);
    }

    public function test_other_servers_rows_are_not_ours_to_fail(): void
    {
        $theirs = Deployment::query()->create([
            'server_name' => 'other-node',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_BUILDING,
        ]);

        $this->command()->markInterrupted(15);

        $this->assertSame(Deployment::STATUS_BUILDING, $theirs->fresh()->status);
    }

    public function test_the_live_release_is_never_renamed(): void
    {
        if (! @symlink($this->release('20260813_101500_live123'), $this->root.DIRECTORY_SEPARATOR.'current')) {
            $this->markTestSkipped('creating symlinks is not permitted in this environment.');
        }

        $live = $this->root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'20260813_101500_live123';

        Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_BUILDING,
            'release_path' => $live,
        ]);

        $this->command()->markInterrupted(15);

        $this->assertDirectoryExists($live, 'an interrupt after the swap must not rename the tree being served');
        $this->assertDirectoryDoesNotExist($live.'.failed');
    }
}
