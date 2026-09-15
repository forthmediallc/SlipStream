<?php

namespace Slipstream\Tests\Unit;

use RuntimeException;
use Slipstream\Support\PhpBinary;
use Slipstream\Tests\TestCase;

class PhpBinaryTest extends TestCase
{
    /**
     * The production failure: a PHP-FPM worker (dashboard click, webhook)
     * whose PHP_BINARY is the daemon. The daemon must never come back, and the
     * CLI matching FPM's version wins over the unversioned alternatives link,
     * which on a multi-version box can be a different PHP.
     */
    public function test_an_fpm_worker_gets_the_cli_of_its_own_version_never_the_daemon(): void
    {
        $resolver = $this->fpmWorker(executables: ['/usr/sbin/php-fpm8.4', '/usr/bin/php8.4', '/usr/bin/php']);

        $this->assertSame('/usr/bin/php8.4', $this->posix($resolver->path()));
        $this->assertSame(PhpBinary::ORIGIN_DETECTED, $resolver->origin());
    }

    public function test_the_unversioned_cli_is_the_next_choice(): void
    {
        $resolver = $this->fpmWorker(executables: ['/usr/sbin/php-fpm8.4', '/usr/bin/php']);

        $this->assertSame('/usr/bin/php', $this->posix($resolver->path()));
    }

    public function test_path_is_searched_last(): void
    {
        $resolver = $this->fpmWorker(executables: ['/usr/sbin/php-fpm8.4'], onPath: ['php' => '/usr/local/bin/php']);

        $this->assertSame('/usr/local/bin/php', $resolver->path());
        $this->assertSame(PhpBinary::ORIGIN_DETECTED, $resolver->origin());
    }

    public function test_a_server_binary_found_on_path_is_refused(): void
    {
        $resolver = $this->fpmWorker(executables: [], onPath: ['php' => '/usr/sbin/php-fpm']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SLIPSTREAM_PHP');

        $resolver->path();
    }

    public function test_nothing_found_names_what_was_tried_and_the_override(): void
    {
        $resolver = $this->fpmWorker(executables: ['/usr/sbin/php-fpm8.4']);

        try {
            $resolver->path();
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('php8.4', $e->getMessage());
            $this->assertStringContainsString('php on PATH', $e->getMessage());
            $this->assertStringContainsString('fpm-fcgi', $e->getMessage());
            $this->assertStringContainsString('/usr/sbin/php-fpm8.4', $e->getMessage());
            $this->assertStringContainsString('SLIPSTREAM_PHP', $e->getMessage());
        }
    }

    public function test_a_cli_process_uses_its_own_binary(): void
    {
        $resolver = new PhpBinary(
            null,
            sapi: 'cli',
            binary: '/opt/php/bin/php',
            bindir: '/usr/bin',
            version: '8.4',
            isExecutable: fn (string $path) => $path === '/opt/php/bin/php',
            findOnPath: fn () => null,
        );

        $this->assertSame('/opt/php/bin/php', $resolver->path());
        $this->assertSame(PhpBinary::ORIGIN_SAPI, $resolver->origin());
    }

    public function test_an_explicit_executable_path_wins(): void
    {
        $resolver = $this->fpmWorker(executables: ['/opt/php/bin/php', '/usr/bin/php8.4'], configured: '/opt/php/bin/php');

        $this->assertSame('/opt/php/bin/php', $resolver->path());
        $this->assertSame(PhpBinary::ORIGIN_CONFIGURED, $resolver->origin());
    }

    public function test_an_explicit_command_name_is_looked_up_on_path(): void
    {
        $resolver = $this->fpmWorker(executables: [], configured: 'php8.4', onPath: ['php8.4' => '/usr/bin/php8.4']);

        $this->assertSame('/usr/bin/php8.4', $resolver->path());
        $this->assertSame(PhpBinary::ORIGIN_CONFIGURED, $resolver->origin());
    }

    public function test_an_explicit_path_that_does_not_exist_throws_rather_than_guessing(): void
    {
        $resolver = $this->fpmWorker(executables: ['/usr/bin/php8.4'], configured: '/opt/php/bin/php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"/opt/php/bin/php", which is not an executable file');

        $resolver->path();
    }

    /**
     * A config published before auto-detection defaulted php_binary to
     * PHP_BINARY — which, evaluated by an FPM worker, is the daemon. That must
     * read as "not configured", or every install with a stale published config
     * keeps the bug.
     */
    public function test_a_configured_server_binary_is_treated_as_unset(): void
    {
        $resolver = $this->fpmWorker(
            executables: ['/usr/sbin/php-fpm8.4', '/usr/bin/php8.4'],
            configured: '/usr/sbin/php-fpm8.4',
        );

        $this->assertSame('/usr/bin/php8.4', $this->posix($resolver->path()));
        $this->assertSame(PhpBinary::ORIGIN_DETECTED, $resolver->origin());
    }

    public function test_it_recognises_server_binaries_in_every_packaged_spelling(): void
    {
        foreach (['php-fpm', '/usr/sbin/php-fpm8.4', 'php8.4-fpm', '/usr/sbin/php-fpm84', 'php-cgi', 'C:\\php\\php-cgi.exe', 'PHP-FPM.EXE'] as $server) {
            $this->assertTrue(PhpBinary::isServerBinary($server), $server);
        }

        foreach (['php', '/usr/bin/php8.4', 'php.exe', 'C:\\Herd\\php84\\php.exe', 'phpdbg', 'php-cli'] as $cli) {
            $this->assertFalse(PhpBinary::isServerBinary($cli), $cli);
        }
    }

    /**
     * A resolver seeing what an FPM worker on Debian sees: its own binary is
     * the daemon, the build's bindir is /usr/bin, and the filesystem holds
     * exactly the executables given.
     *
     * @param  list<string>  $executables
     * @param  array<string, string>  $onPath
     */
    protected function fpmWorker(array $executables, ?string $configured = null, array $onPath = []): PhpBinary
    {
        return new PhpBinary(
            $configured,
            sapi: 'fpm-fcgi',
            binary: '/usr/sbin/php-fpm8.4',
            bindir: '/usr/bin',
            version: '8.4',
            isExecutable: fn (string $path) => in_array($this->posix($path), $executables, true),
            findOnPath: fn (string $name) => $onPath[$name] ?? null,
        );
    }

    /**
     * Candidates are joined with the host's separator; compare them as POSIX
     * paths so the same expectations hold on Windows.
     */
    protected function posix(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
