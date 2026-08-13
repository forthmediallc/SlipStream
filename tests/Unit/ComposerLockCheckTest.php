<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Diagnostics\ComposerLockCheck;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

class ComposerLockCheckTest extends TestCase
{
    protected string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->gitAvailable()) {
            $this->markTestSkipped('git is not available.');
        }

        $this->repo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-lock-'.bin2hex(random_bytes(4));
        mkdir($this->repo, 0777, true);

        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->repo);

        parent::tearDown();
    }

    protected function gitAvailable(): bool
    {
        $probe = new Process(DIRECTORY_SEPARATOR === '\\' ? ['where', 'git'] : ['which', 'git']);
        $probe->run();

        return $probe->isSuccessful();
    }

    protected function git(array $args): void
    {
        $process = new Process(['git', ...$args], $this->repo);
        $process->setTimeout(15);
        $process->mustRun();
    }

    protected function removeTree(string $path): void
    {
        foreach (glob($path.DIRECTORY_SEPARATOR.'{,.}*', GLOB_BRACE) ?: [] as $child) {
            if (in_array(basename($child), ['.', '..'], true)) {
                continue;
            }
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @chmod($child, 0666);
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    protected function writeLock(array $packageNames): void
    {
        file_put_contents(
            $this->repo.DIRECTORY_SEPARATOR.'composer.lock',
            json_encode([
                'packages' => array_map(fn ($name) => ['name' => $name], $packageNames),
                'packages-dev' => [],
            ], JSON_PRETTY_PRINT)
        );
        file_put_contents($this->repo.DIRECTORY_SEPARATOR.'composer.json', '{"name":"acme/app"}');
    }

    protected function check(): ComposerLockCheck
    {
        return new ComposerLockCheck($this->repo, 15);
    }

    public function test_it_detects_a_git_repo(): void
    {
        $this->assertTrue($this->check()->isGitRepo());
        $this->assertFalse((new ComposerLockCheck(sys_get_temp_dir().'/definitely-not-a-repo-'.bin2hex(random_bytes(4))))->isGitRepo());
    }

    public function test_committed_lock_containing_the_package_passes(): void
    {
        $this->writeLock(['laravel/framework', ComposerLockCheck::PACKAGE]);
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'add slipstream']);

        $check = $this->check();
        $this->assertTrue($check->committedLockHasPackage());
        $this->assertFalse($check->composerFilesDirty());
    }

    public function test_committed_lock_without_the_package_is_reported(): void
    {
        $this->writeLock(['laravel/framework']);
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'no slipstream yet']);

        $this->assertFalse($this->check()->committedLockHasPackage());
    }

    public function test_uncommitted_composer_changes_are_dirty(): void
    {
        $this->writeLock(['laravel/framework']);
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'initial']);

        // Install happened locally but was never committed — the exact case
        // that deploys a release without the package.
        $this->writeLock(['laravel/framework', ComposerLockCheck::PACKAGE]);

        $check = $this->check();
        $this->assertTrue($check->composerFilesDirty());
        $this->assertFalse($check->committedLockHasPackage(), 'HEAD still lacks the package');
    }

    public function test_repo_without_commits_answers_unknown(): void
    {
        $this->assertNull($this->check()->committedLockHasPackage());
    }
}
