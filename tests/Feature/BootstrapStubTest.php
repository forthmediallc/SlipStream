<?php

namespace Slipstream\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * stubs/slipstream-bootstrap.sh against a local origin: which commit its
 * throwaway checkout is, since composer scripts and artisan run from it with
 * the production .env before any release exists. Only released code may.
 * The origin's artisan records the commit it runs from and its options.
 */
class BootstrapStubTest extends TestCase
{
    protected string $base;

    /** @var array<string, string> commit by name */
    protected array $commits = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('the stub is a POSIX shell script.');
        }
        foreach ([['git', '--version'], ['sh', '-c', 'exit 0']] as $probe) {
            if ((new Process($probe))->run() !== 0) {
                $this->markTestSkipped('needs git and sh on the PATH.');
            }
        }

        $this->base = sys_get_temp_dir().'/ss-stub-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/origin', 0777, true);
        mkdir($this->base.'/app', 0777, true);

        // main: v1.0.0 (also branch production), v1.2.0, then an unreleased
        // tip carrying a pre-release tag and a tag that is not a version.
        $this->git('init', '-q');
        $this->git('checkout', '-q', '-b', 'main');
        file_put_contents($this->base.'/origin/artisan', <<<'PHP'
            <?php
            $head = trim((string) shell_exec('git -C '.escapeshellarg(__DIR__).' rev-parse HEAD'));
            file_put_contents(getenv('SLIPSTREAM_APP_ROOT').'/ran.txt', $head.' '.implode(' ', array_slice($argv, 1)));
            PHP);
        $this->git('add', '-A');
        $this->commit('released', 'v1.0.0');
        $this->git('branch', 'production');
        $this->commit('newest release', 'v1.2.0');
        $this->commit('unreleased', 'v2.0.0-rc.1');
        $this->git('tag', 'vault-archive');
    }

    protected function tearDown(): void
    {
        if (isset($this->base)) {
            $this->removeTree($this->base);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refs(): array
    {
        return [
            'a branch' => ["SLIPSTREAM_DEPLOY_REF=refs/heads/production\n", 'released'],
            'an exact tag' => ["SLIPSTREAM_DEPLOY_REF=refs/tags/v1.0.0\n", 'released'],
            'a tag pattern: the highest release' => ["export SLIPSTREAM_DEPLOY_REF=\"v*\"\r\n", 'newest release'],
            'pre-releases when allowed' => ["SLIPSTREAM_DEPLOY_REF=v*\nSLIPSTREAM_DEPLOY_PRERELEASES=true\n", 'unreleased'],
            'no ref: the deploy branch' => ["SLIPSTREAM_BRANCH=production\n", 'released'],
        ];
    }

    #[DataProvider('refs')]
    public function test_the_checkout_is_the_deploy_ref_never_the_default_branch(string $env, string $expected): void
    {
        file_put_contents($this->base.'/app/.env', "APP_KEY=x\n".$env);

        $this->assertSame(0, $this->stub()->getExitCode());
        $this->assertStringStartsWith($this->commits[$expected].' slipstream:bootstrap --repo=file://', $this->ran());
    }

    public function test_bootstrap_branch_overrides_it_and_options_pass_through(): void
    {
        file_put_contents($this->base.'/app/.env', "APP_KEY=x\nSLIPSTREAM_DEPLOY_REF=v*\n");

        $process = $this->stub(['BOOTSTRAP_BRANCH' => 'main', 'BOOTSTRAP_ARGS' => '--no-fallback'], ['--verbose']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith($this->commits['unreleased'].' ', $this->ran());
        $this->assertStringEndsWith('--no-interaction --no-fallback --verbose', $this->ran());
    }

    public function test_a_pattern_no_tag_matches_stops_before_any_checkout(): void
    {
        file_put_contents($this->base.'/app/.env', "APP_KEY=x\nSLIPSTREAM_DEPLOY_REF=release-*\n");

        $process = $this->stub();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('no tag on', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->base.'/app/ran.txt');
        $this->assertSame([], glob($this->base.'/app/.bootstrap.*') ?: [], 'nothing left behind');
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $args
     */
    protected function stub(array $env = [], array $args = []): Process
    {
        $process = new Process(['sh', dirname(__DIR__, 2).'/stubs/slipstream-bootstrap.sh', ...$args], $this->base, $env + [
            'APP_ROOT' => $this->base.'/app',
            'REPO' => 'file://'.$this->base.'/origin',
            'PHP' => PHP_BINARY,
            'COMPOSER' => 'true',
            'BOOTSTRAP_BRANCH' => '',
            'BOOTSTRAP_ARGS' => '',
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    protected function ran(): string
    {
        return trim((string) @file_get_contents($this->base.'/app/ran.txt'));
    }

    protected function commit(string $message, string $tag): void
    {
        $this->git('commit', '-q', '--allow-empty', '-m', $message);
        $this->git('tag', '-a', $tag, '-m', $tag);
        $this->commits[$message] = trim($this->git('rev-parse', 'HEAD'));
    }

    protected function git(string ...$args): string
    {
        return (new Process(['git', '-C', $this->base.'/origin', '-c', 'user.name=Slipstream', '-c', 'user.email=slipstream@example.com', ...$args]))
            ->mustRun()
            ->getOutput();
    }
}
