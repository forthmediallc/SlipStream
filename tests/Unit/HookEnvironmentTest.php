<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Deploy\EventLogger;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Deploy\ShellRunner;
use Slipstream\Models\Deployment;
use Slipstream\Support\HookEnvironment;
use Slipstream\Tests\TestCase;

/**
 * What a hook sees, asked of a real child process: everything, as deploys
 * always ran, or — in artifact mode or on request — none of the .env, with
 * the proxy and whatever SLIPSTREAM_HOOK_ENV_PASS names still there. The
 * detached deploy inherits in every mode.
 */
class HookEnvironmentTest extends TestCase
{
    /** What Laravel's Dotenv does with every .env line when config is not cached. */
    protected const DOTENV = ['APP_KEY' => 'base64:secret', 'DB_PASSWORD' => 'hunter2'];

    protected const PROCESS = ['HTTPS_PROXY' => 'http://proxy.internal:3128', 'EXTRA_ONE' => 'yes', 'SLIPSTREAM_SIGNING_KEY' => 'sodium:never'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('slipstream.logging.file_log', false);
        foreach (self::DOTENV + self::PROCESS as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::DOTENV + self::PROCESS) as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        parent::tearDown();
    }

    public function test_git_mode_hands_hooks_everything_as_before(): void
    {
        $seen = $this->childSees();

        $this->assertSame(HookEnvironment::INHERIT, HookEnvironment::mode());
        $this->assertSame('base64:secret', $seen['APP_KEY']);
        $this->assertSame('hunter2', $seen['DB_PASSWORD']);
        $this->assertTrue($seen['PATH']);
        $this->assertFalse($seen['SLIPSTREAM_SIGNING_KEY'], 'CI\'s signing key never reaches a hook');
    }

    public function test_artifact_mode_keeps_the_env_out_of_a_real_child(): void
    {
        config()->set('slipstream.release.source', 'artifact');
        config()->set('slipstream.deploy.hook_env_pass', 'EXTRA_ONE');

        $seen = $this->childSees();

        $this->assertSame(HookEnvironment::MINIMAL, HookEnvironment::mode());
        $this->assertFalse($seen['APP_KEY']);
        $this->assertFalse($seen['DB_PASSWORD']);
        $this->assertSame('http://proxy.internal:3128', $seen['HTTPS_PROXY'], 'a proxy still reaches the hook');
        $this->assertSame('yes', $seen['EXTRA_ONE'], 'SLIPSTREAM_HOOK_ENV_PASS names extra variables');
        $this->assertTrue($seen['PATH']);
        $this->assertFalse($seen['SLIPSTREAM_SIGNING_KEY']);
    }

    public function test_the_setting_wins_in_either_mode(): void
    {
        config()->set('slipstream.deploy.hook_env', 'minimal');
        $this->assertFalse($this->childSees()['APP_KEY']);

        config()->set('slipstream.release.source', 'artifact');
        config()->set('slipstream.deploy.hook_env', 'inherit');
        $this->assertSame('base64:secret', $this->childSees()['APP_KEY']);
    }

    public function test_the_detached_deploy_inherits_in_every_mode(): void
    {
        config()->set('slipstream.release.source', 'artifact');

        $environment = (new class extends ProcessDetacher
        {
            public function __construct() {}

            public function exposed(): array
            {
                return $this->environment();
            }
        })->exposed();

        $this->assertSame('http://proxy.internal:3128', $environment['HTTPS_PROXY'], 'it downloads from the store through the proxy');
        $this->assertSame('base64:secret', $environment['APP_KEY']);
        $this->assertArrayHasKey('PATH', $environment);
    }

    /**
     * @return array<string, string|bool>  each variable as the child saw it; PATH as whether it had one
     */
    protected function childSees(): array
    {
        $deployment = Deployment::query()->create([
            'server_name' => 'test-node',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_BUILDING,
        ]);

        $names = array_keys(self::DOTENV + self::PROCESS);
        [$code, $output] = (new ShellRunner(new EventLogger($deployment)))->run([
            PHP_BINARY, '-r',
            'echo json_encode(array_combine($n = json_decode($argv[1], true), array_map("getenv", $n)) + ["PATH" => getenv("PATH") !== false]);',
            json_encode($names),
        ]);

        $this->assertSame(0, $code);

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }
}
