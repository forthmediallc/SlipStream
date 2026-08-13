<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Support\Sudo;
use Slipstream\Tests\TestCase;

class SudoTest extends TestCase
{
    public function test_configured_true_forces_non_interactive_sudo(): void
    {
        config()->set('slipstream.deploy.use_sudo', true);
        $this->assertSame('sudo -n ', Sudo::prefix());

        config()->set('slipstream.deploy.use_sudo', '1');
        $this->assertSame('sudo -n ', Sudo::prefix());

        // env() passes strings through unfiltered, so SLIPSTREAM_USE_SUDO=true
        // arrives here as the string "true".
        config()->set('slipstream.deploy.use_sudo', 'true');
        $this->assertSame('sudo -n ', Sudo::prefix());
    }

    public function test_configured_false_disables_sudo(): void
    {
        foreach ([false, '0', 0, 'false'] as $value) {
            config()->set('slipstream.deploy.use_sudo', $value);
            $this->assertSame('', Sudo::prefix(), 'falsy config value: '.var_export($value, true));
        }
    }

    public function test_auto_never_uses_sudo_on_windows_or_as_root(): void
    {
        config()->set('slipstream.deploy.use_sudo', null);

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

        $this->assertSame(
            $isWindows || $isRoot ? '' : 'sudo -n ',
            Sudo::prefix()
        );
    }

    public function test_stub_path_points_at_the_shipped_sudoers_file(): void
    {
        $path = Sudo::stubPath();

        $this->assertFileExists($path);
        $this->assertStringContainsString('NOPASSWD', (string) file_get_contents($path));
    }

    public function test_permission_probe_is_unknown_on_windows(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows-only expectation.');
        }

        $this->assertNull(Sudo::canRunWithoutPassword(['systemctl', 'reload', 'php8.4-fpm']));
    }
}
