<?php

namespace Slipstream\Release;

/**
 * Where a node learns what to deploy. Today that is the git remote; the
 * interface is the seam for an artifact source later — a pointer (an S3
 * object, an SSM parameter) naming a tarball CI built once, so nodes in a
 * CDE need no egress to GitHub, Packagist or npm at all.
 */
interface ReleaseSource
{
    /**
     * What the deploy ref names right now.
     *
     * @throws \RuntimeException when the source cannot be read
     */
    public function latest(DeployRef $ref): ReleaseRef;
}
