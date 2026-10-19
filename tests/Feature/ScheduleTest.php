<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Slipstream\Tests\TestCase;

/**
 * The heartbeat and prune are per-node work. Laravel's overlap mutex lives in
 * the default cache store under a name built from the command alone, so on a
 * fleet sharing that store one node's run would make the others skip theirs.
 */
class ScheduleTest extends TestCase
{
    public function test_each_node_holds_its_own_overlap_mutex(): void
    {
        $heartbeat = $this->event('slipstream:heartbeat');
        $prune = $this->event('slipstream:prune');

        $this->assertTrue($heartbeat->withoutOverlapping);
        $this->assertTrue($prune->withoutOverlapping);
        $this->assertSame('framework'.DIRECTORY_SEPARATOR.'schedule-'.sha1('slipstream-heartbeat|test-node'), $heartbeat->mutexName());
        $this->assertSame('framework'.DIRECTORY_SEPARATOR.'schedule-'.sha1('slipstream-prune|test-node'), $prune->mutexName());
    }

    public function test_the_heartbeat_runs_in_the_background_so_the_poll_jitter_holds_nothing_up(): void
    {
        $this->assertTrue($this->event('slipstream:heartbeat')->runInBackground);
    }

    protected function event(string $command): Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail("{$command} is not scheduled.");
    }
}
