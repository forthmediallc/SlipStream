<?php

namespace Slipstream\Tests\Support;

use RuntimeException;
use Slipstream\Release\DeployRef;
use Slipstream\Release\ReleaseHistory;
use Slipstream\Release\ReleaseRef;
use Slipstream\Release\ReleaseSource;

/**
 * Stands in for `git ls-remote`: the deploy ref resolves to whatever the test
 * says, or fails the way a missing deploy key would.
 */
class FakeReleaseSource implements ReleaseHistory, ReleaseSource
{
    public ?ReleaseRef $release = null;

    public ?string $error = null;

    public int $calls = 0;

    /**
     * Commits the ref contains, for contains(); null means every commit.
     *
     * @var list<string>|null
     */
    public ?array $contained = null;

    public function latest(DeployRef $ref): ReleaseRef
    {
        $this->calls++;

        if ($this->error !== null) {
            throw new RuntimeException($this->error);
        }

        return $this->release ?? throw new RuntimeException('FakeReleaseSource: no release set');
    }

    public function contains(DeployRef $ref, ReleaseRef $head, string $sha): bool
    {
        return $this->contained === null || in_array(strtolower($sha), $this->contained, true);
    }

    public function at(string $sha, string $name = 'production', bool $tag = false): static
    {
        $this->release = new ReleaseRef(strtolower($sha), $name, $tag);
        $this->error = null;

        return $this;
    }

    public function failing(string $error): static
    {
        $this->error = $error;

        return $this;
    }

    public function containing(string ...$shas): static
    {
        $this->contained = array_map('strtolower', $shas);

        return $this;
    }
}
