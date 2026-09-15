<?php

namespace Slipstream\Tests\Feature;

use RuntimeException;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Tests\TestCase;

class ProcessDetacherTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-detach-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'artisan', "<?php\n");
        config()->set('slipstream.app_root', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_the_command_is_built_from_the_resolved_cli(): void
    {
        $detacher = $this->detacher(php: '/usr/bin/php8.4');

        $detacher->spawnArtisan(['slipstream:deploy', '--deployment=2'], $this->root);

        $this->assertCount(1, $detacher->launched);
        $cmd = $detacher->launched[0];
        $this->assertStringContainsString('/usr/bin/php8.4', $cmd);
        $this->assertStringContainsString('artisan', $cmd);
        $this->assertStringContainsString('slipstream:deploy', $cmd);
        $this->assertStringContainsString('--deployment=2', $cmd);
        $this->assertStringNotContainsString('php-fpm', $cmd);
    }

    /**
     * Under PHPUnit this is a CLI, so the resolver's answer is PHP_BINARY
     * itself; what matters is that what reaches the shell came through it.
     */
    public function test_the_real_resolver_never_hands_the_shell_a_server_binary(): void
    {
        $detacher = $this->detacher();

        $detacher->spawnArtisan(['slipstream:heartbeat'], $this->root);

        $this->assertStringContainsString(PHP_BINARY, $detacher->launched[0]);
        $this->assertStringNotContainsString('php-fpm', $detacher->launched[0]);
    }

    /**
     * The evidence a stuck deploy leaves behind: the detach log. Its first
     * line is the exact command, so a log holding only an interpreter's usage
     * text names the wrong binary that produced it — and it stays 0600,
     * because deploy output echoes secrets.
     */
    public function test_the_detach_log_opens_with_the_exact_command_and_is_private(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('the detach log is written on the setsid path only.');
        }

        $detacher = $this->detacher(php: '/usr/bin/php8.4');

        $detacher->spawnArtisan(['slipstream:deploy', '--deployment=2'], $this->root);

        $logs = glob($this->root.'/deploy/logs/detach-*.log') ?: [];
        $this->assertCount(1, $logs);
        $this->assertStringEndsWith('-deploy-2.log', $logs[0]);
        $this->assertSame(0600, fileperms($logs[0]) & 0777);

        $first = strtok((string) file_get_contents($logs[0]), "\n");
        $this->assertStringContainsString("'/usr/bin/php8.4'", $first);
        $this->assertStringContainsString('--deployment=2', $first);
        $this->assertStringContainsString($this->root, $first);

        $this->assertStringContainsString('setsid', $detacher->launched[0]);
        $this->assertStringContainsString(escapeshellarg($logs[0]), $detacher->launched[0]);
    }

    /**
     * No PHP CLI means nothing is launched and nothing is logged: the
     * exception is the record, and every caller turns it into a failed row.
     */
    public function test_an_unresolvable_php_binary_throws_before_anything_is_launched(): void
    {
        $detacher = $this->detacher(fail: true);

        try {
            $detacher->spawnArtisan(['slipstream:deploy', '--deployment=2'], $this->root);
            $this->fail('expected the resolver failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('no php (fake)', $e->getMessage());
        }

        $this->assertSame([], $detacher->launched);
        $this->assertSame([], glob($this->root.'/deploy/logs/detach-*.log') ?: []);
    }

    /**
     * A detacher that captures instead of launching. php null = the real
     * resolver; a string = that binary; fail = a resolver failure.
     *
     * @return ProcessDetacher&object{launched: list<string>}
     */
    protected function detacher(?string $php = null, bool $fail = false): ProcessDetacher
    {
        return new class($php, $fail) extends ProcessDetacher
        {
            /** @var list<string> */
            public array $launched = [];

            public function __construct(private ?string $php, private bool $fail) {}

            protected function phpBinary(): string
            {
                if ($this->fail) {
                    throw new RuntimeException('no php (fake)');
                }

                return $this->php ?? parent::phpBinary();
            }

            protected function launch(string $cmd, string $cwd): void
            {
                $this->launched[] = $cmd;
            }

            protected function launchWindows(string $commandLine): void
            {
                $this->launched[] = $commandLine;
            }
        };
    }
}
