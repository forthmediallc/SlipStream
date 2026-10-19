<?php

namespace Slipstream\Tests\Unit;

use InvalidArgumentException;
use Slipstream\Release\DeployRef;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\TestCase;

class DeployRefTest extends TestCase
{
    public function test_branches_exact_tags_and_tag_patterns_parse(): void
    {
        $this->assertSame([DeployRef::BRANCH, 'production'], $this->parts('refs/heads/production'));
        $this->assertSame([DeployRef::BRANCH, 'release/2026'], $this->parts('release/2026'));
        $this->assertSame([DeployRef::TAG, 'v1.4.2'], $this->parts('refs/tags/v1.4.2'));
        $this->assertSame([DeployRef::TAGS, 'v*'], $this->parts('refs/tags/v*'));
        // Branch names cannot hold glob characters, so a bare pattern is tags.
        $this->assertSame([DeployRef::TAGS, 'v*'], $this->parts('v*'));
    }

    public function test_an_unset_ref_follows_the_configured_branch_never_the_dashboard(): void
    {
        config()->set('slipstream.trigger.ref', null);
        config()->set('slipstream.deploy.branch', 'main');
        app(SettingsRepository::class)->set('branch', 'dashboard-branch');

        $ref = DeployRef::fromConfig();

        $this->assertSame([DeployRef::BRANCH, 'main'], [$ref->kind, $ref->name]);
        $this->assertSame('refs/heads/main', $ref->full());
    }

    public function test_unusable_refs_are_refused(): void
    {
        foreach (['-oops', 'refs/heads/a..b', 'refs/tags/v*;rm', ''] as $raw) {
            try {
                DeployRef::parse($raw);
                $this->fail("accepted \"{$raw}\"");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parts(string $raw): array
    {
        $ref = DeployRef::parse($raw);

        return [$ref->kind, $ref->name];
    }
}
