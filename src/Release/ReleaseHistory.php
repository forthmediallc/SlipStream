<?php

namespace Slipstream\Release;

/**
 * Whether a commit is part of what a deploy ref names now. Bootstrap asks
 * before it falls back to the fleet's last good commit, so a value in the
 * shared store can only send a node a commit the protected ref vouches for.
 *
 * Separate from ReleaseSource so a source written against 0.5.0 keeps
 * working; bootstrap simply never falls back with a source that cannot say.
 */
interface ReleaseHistory
{
    /**
     * For a branch: $sha is in its history up to $head. For one tag: $sha
     * is the tag's commit. For a tag pattern: a tag the ref matches, under
     * the rules that pick the release, points at $sha now.
     *
     * @param  ReleaseRef  $head  what the ref resolves to now
     *
     * @throws \RuntimeException when the source cannot be read
     */
    public function contains(DeployRef $ref, ReleaseRef $head, string $sha): bool;
}
