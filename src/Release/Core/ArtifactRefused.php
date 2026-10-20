<?php

namespace Slipstream\Release\Core;

/**
 * A release a node will not take, and at which stage of checking it it found
 * out: pointer, statement, manifest, php, archive or files. Refused content
 * is deleted at once, never kept for inspection — there is nothing in it to
 * re-run, and unverified bytes stay off a node.
 */
final class ArtifactRefused extends \RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly string $reason,
        public readonly ?string $sha = null,
        /** @var array<string, scalar|null> */
        public readonly array $context = [],
    ) {
        parent::__construct("Refusing artifact ({$stage}): {$reason}");
    }
}
