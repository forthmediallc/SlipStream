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

    protected function writeLock(array $packageNames, array $devPackageNames = []): void
    {
        file_put_contents(
            $this->repo.DIRECTORY_SEPARATOR.'composer.lock',
            json_encode([
                'packages' => array_map(fn ($name) => ['name' => $name], $packageNames),
                'packages-dev' => array_map(fn ($name) => ['name' => $name], $devPackageNames),
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

    /**
     * `composer require --dev` puts the package in packages-dev, which
     * `composer install --no-dev` — what every deploy runs — never installs.
     * Counting it as present would bless exactly the lockfile the deploy then
     * fails on.
     */
    public function test_a_dev_only_requirement_does_not_count(): void
    {
        $this->writeLock(['laravel/framework'], [ComposerLockCheck::PACKAGE]);
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'require --dev slipstream']);

        $this->assertFalse($this->check()->committedLockHasPackage());
    }

    public function test_lock_lists_reads_a_raw_lock_body(): void
    {
        $lock = json_encode(['packages' => [['name' => ComposerLockCheck::PACKAGE]], 'packages-dev' => []]);

        $this->assertTrue(ComposerLockCheck::lockLists($lock));
        $this->assertFalse(ComposerLockCheck::lockLists('{"packages": []}'));
        $this->assertNull(ComposerLockCheck::lockLists(null));
        $this->assertNull(ComposerLockCheck::lockLists('not json'));
    }

    /**
     * A deploy builds from the bare mirror at the configured branch, so a
     * commit that was never pushed must not read as "ready to deploy".
     */
    public function test_the_mirror_answers_for_what_a_deploy_would_install(): void
    {
        $this->writeLock(['laravel/framework']);
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'no slipstream yet']);
        $this->git(['branch', '-M', 'main']);

        $mirror = $this->repo.'-mirror.git';
        (new Process(['git', 'clone', '--mirror', '-q', $this->repo, $mirror]))->setTimeout(30)->mustRun();

        // Committed locally, never pushed: local HEAD says yes, the mirror — the
        // only thing a deploy reads — still says no.
        $this->writeLock(['laravel/framework', ComposerLockCheck::PACKAGE]);
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'add slipstream']);

        $check = $this->check();
        $this->assertTrue($check->committedLockHasPackage(), 'the local commit has it');

        [$inMirror, $ref] = $check->mirrorLockHasPackage($mirror, 'main');
        $this->assertFalse($inMirror, 'the unpushed commit is not in the mirror');
        $this->assertSame('refs/heads/main', $ref);

        // After a push the mirror agrees.
        (new Process(['git', 'push', '-q', $mirror, 'main'], $this->repo))->setTimeout(30)->mustRun();
        [$afterPush] = $this->check()->mirrorLockHasPackage($mirror, 'main');
        $this->assertTrue($afterPush);

        $this->removeTree($mirror);
    }

    public function test_an_unknown_branch_in_the_mirror_answers_unknown(): void
    {
        $this->writeLock([ComposerLockCheck::PACKAGE]);
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'initial']);
        $this->git(['branch', '-M', 'main']);

        $mirror = $this->repo.'-mirror2.git';
        (new Process(['git', 'clone', '--mirror', '-q', $this->repo, $mirror]))->setTimeout(30)->mustRun();

        [$answer] = $this->check()->mirrorLockHasPackage($mirror, 'branch-that-was-never-pushed');
        $this->assertNull($answer);

        $this->removeTree($mirror);
    }
}
