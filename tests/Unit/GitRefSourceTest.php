<?php

namespace Slipstream\Tests\Unit;

use RuntimeException;
use Slipstream\Release\DeployRef;
use Slipstream\Release\GitRefSource;
use Slipstream\Release\ReleaseRef;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * Choosing the release from `git ls-remote` output, without git, and what the
 * ref contains (the branch case with a real mirror).
 */
class GitRefSourceTest extends TestCase
{
    protected function lsRemote(): string
    {
        $sha = fn (string $c) => str_repeat($c, 40);

        return implode("\n", [
            $sha('1')."\trefs/heads/main",
            $sha('2')."\trefs/heads/production",
            $sha('3')."\trefs/tags/v1.2.0",
            // An annotated tag: its own object, then the commit it points at.
            $sha('4')."\trefs/tags/v1.10.0",
            $sha('5')."\trefs/tags/v1.10.0^{}",
            $sha('6')."\trefs/tags/v1.9.3",
            $sha('7')."\trefs/tags/v2.0.0-rc.1",
            $sha('8')."\trefs/tags/vault-archive",
            $sha('9')."\trefs/tags/other-3.0.0",
            '',
        ]);
    }

    public function test_a_branch_resolves_to_its_tip(): void
    {
        $release = GitRefSource::pick(DeployRef::parse('refs/heads/production'), $this->lsRemote());

        $this->assertSame(str_repeat('2', 40), $release->sha);
        $this->assertSame('production', $release->name);
        $this->assertFalse($release->isTag);
    }

    public function test_the_highest_version_wins_and_an_annotated_tag_resolves_to_its_commit(): void
    {
        $release = GitRefSource::pick(DeployRef::parse('v*'), $this->lsRemote());

        // 1.10.0 beats 1.9.3 (a version sort, not a string sort); the
        // pre-release and the tag that is not a version are skipped.
        $this->assertSame('v1.10.0', $release->name);
        $this->assertSame(str_repeat('5', 40), $release->sha, 'the peeled commit, not the tag object');
        $this->assertTrue($release->isTag);
    }

    public function test_pre_releases_are_opt_in(): void
    {
        $release = GitRefSource::pick(DeployRef::parse('v*'), $this->lsRemote(), prereleases: true);

        $this->assertSame('v2.0.0-rc.1', $release->name);
    }

    public function test_an_exact_tag_resolves_like_any_other(): void
    {
        $release = GitRefSource::pick(DeployRef::parse('refs/tags/v1.10.0'), $this->lsRemote());

        $this->assertSame(str_repeat('5', 40), $release->sha);
    }

    public function test_a_missing_branch_or_an_unmatched_pattern_is_an_error(): void
    {
        foreach (['refs/heads/staging', 'refs/tags/release-*'] as $raw) {
            try {
                GitRefSource::pick(DeployRef::parse($raw), $this->lsRemote());
                $this->fail("resolved \"{$raw}\"");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_pattern_vouches_for_what_its_matching_tags_point_at_under_the_same_rules(): void
    {
        $sha = fn (string $c) => str_repeat($c, 40);

        $this->assertEqualsCanonicalizing(
            [$sha('3'), $sha('5'), $sha('6')],
            GitRefSource::taggedCommits(DeployRef::parse('v*'), $this->lsRemote()),
            'versions only, peeled, no pre-release'
        );
        $this->assertContains($sha('7'), GitRefSource::taggedCommits(DeployRef::parse('v*'), $this->lsRemote(), prereleases: true));
    }

    public function test_containment_takes_nothing_but_a_commit_id(): void
    {
        // The value comes from the shared store; none of these reach git.
        $source = new GitRefSource;
        $head = new ReleaseRef(str_repeat('2', 40), 'production', false);

        foreach (['--upload-pack=touch /tmp/x', 'HEAD', 'production', '', str_repeat('g', 40)] as $value) {
            $this->assertFalse($source->contains(DeployRef::parse('refs/heads/production'), $head, $value), $value);
        }
    }

    public function test_an_exact_tag_contains_its_own_commit_and_nothing_else(): void
    {
        $source = new GitRefSource;
        $ref = DeployRef::parse('refs/tags/v1.10.0');
        $head = new ReleaseRef(str_repeat('5', 40), 'v1.10.0', true);

        $this->assertTrue($source->contains($ref, $head, str_repeat('5', 40)));
        $this->assertFalse($source->contains($ref, $head, str_repeat('3', 40)));
    }

    /**
     * The real check for a branch: `git merge-base --is-ancestor` in the
     * mirror. A commit on another branch, or one the mirror lacks, is out.
     */
    public function test_a_branch_contains_its_own_history_only(): void
    {
        try {
            $hasGit = (new Process(['git', '--version']))->run() === 0;
        } catch (\Throwable) {
            $hasGit = false;
        }
        if (! $hasGit) {
            $this->markTestSkipped('needs git on the PATH.');
        }

        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-gitref-'.bin2hex(random_bytes(4));
        $work = $root.DIRECTORY_SEPARATOR.'work';
        mkdir($work, 0777, true);
        $git = fn (string ...$args) => trim((new Process(
            ['git', '-C', $work, '-c', 'user.name=Slipstream', '-c', 'user.email=slipstream@example.com', ...$args]
        ))->mustRun()->getOutput());

        try {
            $git('init', '-q');
            $git('checkout', '-q', '-b', 'production');
            $git('commit', '-q', '--allow-empty', '-m', 'one');
            $older = $git('rev-parse', 'HEAD');
            $git('commit', '-q', '--allow-empty', '-m', 'two');
            $tip = $git('rev-parse', 'HEAD');
            $git('checkout', '-q', '-b', 'unreviewed', $older);
            $git('commit', '-q', '--allow-empty', '-m', 'never merged');
            $elsewhere = $git('rev-parse', 'HEAD');
            (new Process(['git', 'clone', '-q', '--bare', $work, $root.DIRECTORY_SEPARATOR.'repo.git']))->mustRun();
            config()->set('slipstream.app_root', $root);

            $source = new GitRefSource;
            $ref = DeployRef::parse('refs/heads/production');
            $head = new ReleaseRef($tip, 'production', false);

            $this->assertTrue($source->contains($ref, $head, $older));
            $this->assertTrue($source->contains($ref, $head, $tip));
            $this->assertFalse($source->contains($ref, $head, $elsewhere), 'a branch nobody merged');
            $this->assertFalse($source->contains($ref, $head, str_repeat('a', 40)), 'a commit the mirror lacks');
        } finally {
            $this->removeTree($root);
        }
    }
}
