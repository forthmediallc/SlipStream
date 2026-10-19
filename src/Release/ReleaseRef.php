<?php

namespace Slipstream\Release;

/**
 * A deploy ref resolved to one commit: the branch tip, or the tag chosen.
 */
final class ReleaseRef
{
    public function __construct(
        public readonly string $sha,
        public readonly string $name,
        public readonly bool $isTag,
    ) {}

    public function ref(): string
    {
        return ($this->isTag ? 'refs/tags/' : 'refs/heads/').$this->name;
    }

    public function short(): string
    {
        return substr($this->sha, 0, 7);
    }
}
