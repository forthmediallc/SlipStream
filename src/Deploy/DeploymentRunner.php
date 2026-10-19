<?php

namespace Slipstream\Deploy;

use Illuminate\Support\Facades\Artisan;
use Slipstream\Models\Deployment;

/**
 * Run one deployment row to completion, in this process — what bootstrap
 * needs, since an instance may only take traffic once its deploy finished.
 * A seam so tests can stand in for git and composer.
 */
class DeploymentRunner
{
    public function run(Deployment $deployment): Deployment
    {
        $command = $deployment->trigger === Deployment::TRIGGER_ROLLBACK ? 'slipstream:rollback' : 'slipstream:deploy';

        Artisan::call($command, ['--deployment' => $deployment->id]);

        return $deployment->refresh();
    }
}
