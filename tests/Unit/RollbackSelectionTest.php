<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Deploy\Rollbacker;
use Slipstream\Tests\TestCase;

class RollbackSelectionTest extends TestCase
{
    /**
     * releases newest→oldest: r3 (live), r2, r1.
     * A first rollback from r3 must land on r2 — the one just older.
     */
    public function test_first_rollback_selects_release_just_older_than_live(): void
    {
        $dirs = $this->makeReleases(['r1', 'r2', 'r3']); // r1 oldest
        $newestFirst = array_reverse(array_values($dirs));             // r3, r2, r1
        $live = $dirs['r3'];

        $this->assertSame($dirs['r2'], Rollbacker::pickOlderThanLive($newestFirst, $live));
    }

    /**
     * The bug this guards: a SECOND rollback (now live on r2) must step to r1,
     * never "roll forward" back onto the newer r3.
     */
    public function test_second_consecutive_rollback_steps_further_back_not_forward(): void
    {
        $dirs = $this->makeReleases(['r1', 'r2', 'r3']);
        $newestFirst = array_reverse(array_values($dirs)); // r3, r2, r1
        $live = $dirs['r2'];

        $picked = Rollbacker::pickOlderThanLive($newestFirst, $live);

        $this->assertSame($dirs['r1'], $picked);
        $this->assertNotSame($dirs['r3'], $picked, 'must never select a newer release than live');
    }

    public function test_returns_null_when_live_is_oldest(): void
    {
        $dirs = $this->makeReleases(['r1', 'r2']);
        $newestFirst = array_reverse(array_values($dirs)); // r2, r1
        $live = $dirs['r1'];                  // oldest

        $this->assertNull(Rollbacker::pickOlderThanLive($newestFirst, $live));
    }

    public function test_falls_back_to_newest_non_live_when_live_unknown(): void
    {
        $dirs = $this->makeReleases(['r1', 'r2', 'r3']);
        $newestFirst = array_reverse(array_values($dirs)); // r3, r2, r1

        $this->assertSame($dirs['r3'], Rollbacker::pickOlderThanLive($newestFirst, '/nonexistent/current'));
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string>  name => absolute path (creation order)
     */
    protected function makeReleases(array $names): array
    {
        $base = sys_get_temp_dir().'/ss-rel-'.bin2hex(random_bytes(4));
        mkdir($base, 0777, true);

        $out = [];
        foreach ($names as $name) {
            $path = $base.DIRECTORY_SEPARATOR.$name;
            mkdir($path, 0777, true);
            $out[$name] = realpath($path) ?: $path;
        }

        return $out;
    }
}
