<?php

namespace Slipstream\Tests\Support;

use RuntimeException;
use Slipstream\Release\DeployRef;
use Slipstream\Release\ReleaseRef;
use Slipstream\Release\ReleaseSource;

/**
 * Stands in for `git ls-remote`: the deploy ref resolves to whatever the test
 * says, or fails the way a missing deploy key would.
 */
class FakeReleaseSource implements ReleaseSource
{
    public ?ReleaseRef $release = null;

    public ?string $error = null;

    public int $calls = 0;

    public function latest(DeployRef $ref): ReleaseRef
    {
        $this->calls++;

        if ($this->error !== null) {
            throw new RuntimeException($this->error);
        }

        return $this->release ?? throw new RuntimeException('FakeReleaseSource: no release set');
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
}
