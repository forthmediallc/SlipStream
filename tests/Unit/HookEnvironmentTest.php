<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Support\HookEnvironment;
use Slipstream\Tests\TestCase;

/**
 * What a hook sees: everything, as deploys always ran, or only what it
 * needs — never the .env — in artifact mode or on request.
 */
class HookEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['APP_KEY'] = 'base64:secret';
        $_ENV['SLIPSTREAM_APP_ROOT'] = '/var/www/app';
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_KEY'], $_ENV['SLIPSTREAM_APP_ROOT']);

        parent::tearDown();
    }

    public function test_git_mode_inherits_everything_as_before(): void
    {
        $env = HookEnvironment::build();

        $this->assertSame(HookEnvironment::INHERIT, HookEnvironment::mode());
        $this->assertSame('base64:secret', $env['APP_KEY']);
        $this->assertArrayHasKey('PATH', $env);
    }

    public function test_artifact_mode_hands_hooks_only_what_they_need(): void
    {
        config()->set('slipstream.release.source', 'artifact');

        $env = HookEnvironment::build();

        $this->assertSame(HookEnvironment::MINIMAL, HookEnvironment::mode());
        $this->assertArrayNotHasKey('APP_KEY', $env);
        $this->assertSame('/var/www/app', $env['SLIPSTREAM_APP_ROOT']);
        $this->assertArrayHasKey('PATH', $env);
    }

    public function test_the_setting_wins_in_either_mode(): void
    {
        config()->set('slipstream.deploy.hook_env', 'minimal');
        $this->assertArrayNotHasKey('APP_KEY', HookEnvironment::build());

        config()->set('slipstream.release.source', 'artifact');
        config()->set('slipstream.deploy.hook_env', 'inherit');
        $this->assertSame('base64:secret', HookEnvironment::build()['APP_KEY']);
    }
}
