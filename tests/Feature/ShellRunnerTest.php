<?php

namespace Slipstream\Tests\Feature;

use RuntimeException;
use Slipstream\Deploy\EventLogger;
use Slipstream\Deploy\HookScript;
use Slipstream\Deploy\ShellRunner;
use Slipstream\Models\Deployment;
use Slipstream\Tests\TestCase;

class ShellRunnerTest extends TestCase
{
    protected function runner(): ShellRunner
    {
        config()->set('slipstream.logging.file_log', false);

        $deployment = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_BUILDING,
        ]);

        return new ShellRunner(new EventLogger($deployment));
    }

    public function test_failed_command_message_includes_tail_of_output(): void
    {
        $php = PHP_BINARY;

        try {
            $this->runner()->run([
                $php, '-r',
                'fwrite(STDERR, "noise line\nthe real error line\n"); exit(3);',
            ]);
            $this->fail('expected the failing command to throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Command failed (exit 3)', $e->getMessage());
            $this->assertStringContainsString('the real error line', $e->getMessage());
            $this->assertStringContainsString('│', $e->getMessage());
        }
    }

    public function test_failed_command_with_no_output_keeps_the_bare_message(): void
    {
        try {
            $this->runner()->run([PHP_BINARY, '-r', 'exit(4);']);
            $this->fail('expected the failing command to throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Command failed (exit 4)', $e->getMessage());
            $this->assertStringNotContainsString('│', $e->getMessage());
        }
    }

    public function test_tail_is_capped_to_the_last_five_lines(): void
    {
        try {
            $this->runner()->run([
                PHP_BINARY, '-r',
                'for ($i = 1; $i <= 20; $i++) { echo "line{$i}\n"; } exit(1);',
            ]);
            $this->fail('expected the failing command to throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('line20', $e->getMessage());
            $this->assertStringContainsString('line16', $e->getMessage());
            $this->assertStringNotContainsString('line15', $e->getMessage());
        }
    }

    public function test_successful_command_returns_exit_code_and_buffer(): void
    {
        [$code, $buffer] = $this->runner()->run([PHP_BINARY, '-r', 'echo "hello";']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('hello', $buffer);
    }

    /**
     * The real shell, so the fix is proven where it matters: `sh -c` reports
     * the last line's status, and `set -e` first makes the first failure the
     * script's.
     */
    public function test_a_failing_line_only_fails_the_script_with_set_e(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs a POSIX sh.');
        }

        [$code] = $this->runner()->run("false\ntrue", throw: false);
        $this->assertSame(0, $code, 'the trap: the last line decides');

        [$code] = $this->runner()->run(HookScript::withFailFast("false\ntrue"), throw: false);
        $this->assertNotSame(0, $code);
    }
}
