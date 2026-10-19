<?php

namespace Slipstream\Tests\Unit;

use RuntimeException;
use Slipstream\Release\DeployRef;
use Slipstream\Release\GitRefSource;
use Slipstream\Tests\TestCase;

/**
 * Choosing the release from `git ls-remote` output, without git.
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
}
