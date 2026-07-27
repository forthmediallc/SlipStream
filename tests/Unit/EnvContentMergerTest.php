<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Environment\EnvContentMerger;
use Slipstream\Tests\TestCase;

class EnvContentMergerTest extends TestCase
{
    protected EnvContentMerger $merger;

    /** @var list<string> */
    protected array $keys = ['SLIPSTREAM_SERVER', 'APP_URL'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new EnvContentMerger;
    }

    public function test_target_protected_value_replaces_pushed_value_in_place(): void
    {
        $pushed = "APP_ENV=production\nSLIPSTREAM_SERVER=web-1\nQUEUE=redis\n";
        $target = "APP_ENV=staging\nSLIPSTREAM_SERVER=web-2\n";

        $out = $this->merger->merge($pushed, $target, $this->keys);

        $this->assertStringContainsString('APP_ENV=production', $out);   // pushed non-protected wins
        $this->assertStringContainsString('SLIPSTREAM_SERVER=web-2', $out); // target's protected value kept
        $this->assertStringNotContainsString('web-1', $out);
        $this->assertStringContainsString('QUEUE=redis', $out);

        // Kept at the pushed file's position for that key (line index 1).
        $this->assertSame('SLIPSTREAM_SERVER=web-2', explode("\n", $out)[1]);
    }

    public function test_pushed_only_protected_key_is_dropped(): void
    {
        $pushed = "APP_ENV=production\nSLIPSTREAM_SERVER=web-1\n";
        $target = "APP_ENV=staging\n"; // no SLIPSTREAM_SERVER

        $out = $this->merger->merge($pushed, $target, $this->keys);

        $this->assertStringNotContainsString('SLIPSTREAM_SERVER', $out);
        $this->assertStringContainsString('APP_ENV=production', $out);
    }

    public function test_target_only_protected_key_is_appended_at_eof(): void
    {
        $pushed = "APP_ENV=production\n"; // base omitted the key
        $target = "APP_ENV=staging\nSLIPSTREAM_SERVER=web-2\n";

        $out = $this->merger->merge($pushed, $target, $this->keys);

        $lines = explode("\n", rtrim($out, "\n"));
        $this->assertSame('SLIPSTREAM_SERVER=web-2', end($lines));
    }

    public function test_export_prefix_and_whitespace_are_matched(): void
    {
        $pushed = "SLIPSTREAM_SERVER=web-1\n";
        $target = "  export SLIPSTREAM_SERVER=web-2\n";

        $out = $this->merger->merge($pushed, $target, $this->keys);

        $this->assertStringContainsString('export SLIPSTREAM_SERVER=web-2', $out);
        $this->assertStringNotContainsString('web-1', $out);
    }

    public function test_last_occurrence_in_target_wins(): void
    {
        $pushed = "SLIPSTREAM_SERVER=web-1\n";
        $target = "SLIPSTREAM_SERVER=old\nSLIPSTREAM_SERVER=new\n";

        $out = $this->merger->merge($pushed, $target, $this->keys);

        $this->assertStringContainsString('SLIPSTREAM_SERVER=new', $out);
        $this->assertStringNotContainsString('=old', $out);
        $this->assertStringNotContainsString('web-1', $out);
    }

    public function test_comments_and_blanks_are_preserved_from_pushed(): void
    {
        $pushed = "# header\n\nAPP_ENV=production\n# tail\n";
        $target = "APP_ENV=staging\n";

        $out = $this->merger->merge($pushed, $target, $this->keys);

        $this->assertStringContainsString('# header', $out);
        $this->assertStringContainsString('# tail', $out);
        $this->assertStringContainsString("\n\nAPP_ENV=production", $out);
    }

    public function test_similar_key_prefix_is_not_treated_as_protected(): void
    {
        // SLIPSTREAM_SERVER must not match SLIPSTREAM_SERVER_NAME.
        $pushed = "SLIPSTREAM_SERVER_NAME=from-push\n";
        $target = "SLIPSTREAM_SERVER_NAME=from-target\n";

        $out = $this->merger->merge($pushed, $target, ['SLIPSTREAM_SERVER']);

        $this->assertStringContainsString('SLIPSTREAM_SERVER_NAME=from-push', $out);
    }

    public function test_empty_protected_list_returns_pushed_unchanged(): void
    {
        $pushed = "A=1\nSLIPSTREAM_SERVER=x\n";

        $this->assertSame($pushed, $this->merger->merge($pushed, "SLIPSTREAM_SERVER=y\n", []));
    }
}
