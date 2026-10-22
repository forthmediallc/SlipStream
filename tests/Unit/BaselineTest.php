<?php

namespace Slipstream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slipstream\Release\Core\Baseline;

/**
 * The record of what a node generated into a release: the caches and, from
 * format 2 on, the compiled views beneath them.
 */
class BaselineTest extends TestCase
{
    protected string $release;

    protected function setUp(): void
    {
        parent::setUp();

        $this->release = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-baseline-'.bin2hex(random_bytes(4));
        mkdir($this->release.'/bootstrap/cache/views', 0777, true);
        file_put_contents($this->release.'/bootstrap/cache/config.php', '<?php return [];');
        file_put_contents($this->release.'/bootstrap/cache/.gitignore', "*\n");
        file_put_contents($this->release.'/bootstrap/cache/views/0a1b.php', '<?php echo "frame"; ?>');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tree($this->release)) as $path) {
            is_link($path) || is_file($path) ? @unlink($path) : @rmdir($path);
        }
        @rmdir($this->release);

        parent::tearDown();
    }

    public function test_the_record_covers_the_compiled_views_beneath_the_caches(): void
    {
        $files = Baseline::record($this->release);

        $this->assertSame(['bootstrap/cache/config.php', 'bootstrap/cache/views/0a1b.php'], array_keys($files));
        $this->assertSame([hash('sha256', '<?php echo "frame"; ?>'), strlen('<?php echo "frame"; ?>')], $files['bootstrap/cache/views/0a1b.php']);
        $this->assertSame([], Baseline::compare($this->release, $files));

        // A compiled view edited, and one that appears: both are drift.
        file_put_contents($this->release.'/bootstrap/cache/views/0a1b.php', '<?php echo "frame"; skim(); ?>');
        file_put_contents($this->release.'/bootstrap/cache/views/ffff.php', '<?php echo "new"; ?>');
        $this->assertSame(['bootstrap/cache/views/0a1b.php', 'bootstrap/cache/views/ffff.php'], Baseline::compare($this->release, $files));
    }

    public function test_a_record_written_before_0_6_4_is_compared_over_what_it_covered(): void
    {
        // The first release a 0.6.3 deploy puts in place compiles its views
        // into the release, under a record that never saw them.
        $old = (string) json_encode(['format' => 1, 'files' => ['bootstrap/cache/config.php' => [hash('sha256', '<?php return [];'), 16]]]);
        $parsed = Baseline::parse($old);

        $this->assertSame(1, $parsed['format']);
        $this->assertSame([], Baseline::compare($this->release, $parsed['files'], $parsed['format']));
        $this->assertSame(['bootstrap/cache/views/0a1b.php'], Baseline::compare($this->release, $parsed['files']), 'over the new scope the views are unrecorded');

        // A format 1 record never named a nested path; one that does is not a record.
        $this->assertNull(Baseline::parse((string) json_encode(['format' => 1, 'files' => ['bootstrap/cache/views/0a1b.php' => ['x', 1]]])));
        $this->assertNull(Baseline::parse((string) json_encode(['format' => 3, 'files' => []])));
    }

    public function test_the_json_round_trips_as_format_2(): void
    {
        $files = Baseline::record($this->release);
        $parsed = Baseline::parse(Baseline::toJson($files));

        $this->assertSame(['format' => 2, 'files' => $files], $parsed);
        $this->assertSame($files, Baseline::fromJson(Baseline::toJson($files)));
        $this->assertSame(['format' => 2, 'files' => []], Baseline::parse(Baseline::toJson([])));
    }

    public function test_a_link_anywhere_under_bootstrap_cache_is_recorded_and_never_followed(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks (run under WSL).');
        }

        $elsewhere = $this->release.'/elsewhere';
        mkdir($elsewhere);
        file_put_contents($elsewhere.'/hidden.php', '<?php');
        $files = Baseline::record($this->release);

        // Views compiled into a directory of the attacker's choosing.
        rename($this->release.'/bootstrap/cache/views', $this->release.'/bootstrap/cache/views-old');
        symlink($elsewhere, $this->release.'/bootstrap/cache/views');

        $now = Baseline::record($this->release);
        $this->assertSame(['symlink', 0], $now['bootstrap/cache/views']);
        $this->assertArrayNotHasKey('bootstrap/cache/views/hidden.php', $now);
        $this->assertContains('bootstrap/cache/views', Baseline::compare($this->release, $files));
    }

    /**
     * @return list<string>
     */
    protected function tree(string $root): array
    {
        $paths = [];
        foreach (@scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $root.'/'.$name;
            $paths[] = $path;
            if (is_dir($path) && ! is_link($path)) {
                array_push($paths, ...$this->tree($path));
            }
        }

        return $paths;
    }
}
