<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Deploy\FailedReleases;
use Slipstream\Deploy\Rollbacker;
use Slipstream\Tests\TestCase;

class FailedReleasesTest extends TestCase
{
    protected string $releases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releases = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-failed-'.bin2hex(random_bytes(4));
        mkdir($this->releases, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->releases.DIRECTORY_SEPARATOR.'*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->releases);

        parent::tearDown();
    }

    protected function release(string $name, int $ageMinutes = 0): string
    {
        $path = $this->releases.DIRECTORY_SEPARATOR.$name;
        mkdir($path, 0777, true);
        touch($path, time() - ($ageMinutes * 60));

        return $path;
    }

    public function test_partition_separates_preserved_failures(): void
    {
        [$releases, $failed] = FailedReleases::partition([
            '/app/releases/20260101_000000_aaaaaaa',
            '/app/releases/20260101_000100_bbbbbbb.failed',
            '/app/releases/20260101_000200_ccccccc',
        ]);

        $this->assertCount(2, $releases);
        $this->assertCount(1, $failed);
        $this->assertStringEndsWith('.failed', $failed[0]);
    }

    public function test_prune_keeps_the_newest_failures(): void
    {
        $newest = $this->release('20260101_000300_ddd.failed', 1);
        $middle = $this->release('20260101_000200_ccc.failed', 10);
        $oldest = $this->release('20260101_000100_bbb.failed', 20);
        $healthy = $this->release('20260101_000400_eee');

        $removed = [];
        FailedReleases::prune($this->releases, 1, function (string $path) use (&$removed) {
            $removed[] = $path;
            rmdir($path);
        });

        $this->assertSame([$middle, $oldest], $removed);
        $this->assertDirectoryExists($newest);
        $this->assertDirectoryExists($healthy, 'a normal release is never touched by failed-release pruning');
    }

    public function test_prune_with_zero_keep_removes_every_failure(): void
    {
        $this->release('a.failed', 1);
        $this->release('b.failed', 2);

        $removed = [];
        FailedReleases::prune($this->releases, 0, function (string $path) use (&$removed) {
            $removed[] = $path;
            rmdir($path);
        });

        $this->assertCount(2, $removed);
    }

    public function test_rollback_never_selects_a_preserved_failure(): void
    {
        // Newest first, as releaseOlderThanLive() sorts them.
        $dirs = [
            '/app/releases/20260101_000300_ddd.failed',
            '/app/releases/20260101_000200_ccc',
            '/app/releases/20260101_000100_bbb',
        ];

        [$candidates] = FailedReleases::partition($dirs);
        $picked = Rollbacker::pickOlderThanLive($candidates, '/app/releases/20260101_000200_ccc');

        $this->assertSame('/app/releases/20260101_000100_bbb', $picked);
    }
}
