<?php

namespace Slipstream\Deploy;

use RuntimeException;

/**
 * The pointer moved on while a deploy was in flight — to a rollback, or a
 * newer release — so finishing it would put live a release the ref no longer
 * names. Not a failure: the deploy stops, its release is removed, and the
 * next check deploys what the pointer names now.
 */
class DeploymentSuperseded extends RuntimeException {}
