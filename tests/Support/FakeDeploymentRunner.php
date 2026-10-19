<?php

namespace Slipstream\Tests\Support;

use Slipstream\Deploy\DeploymentRunner;
use Slipstream\Models\Deployment;

/**
 * Finishes a deployment row without git or composer: a commit listed in
 * $failing fails, anything else becomes the live release (current/RELEASE_SHA).
 */
class FakeDeploymentRunner extends DeploymentRunner
{
    /** @var list<string> */
    public array $failing = [];

    /** @var list<int> */
    public array $ran = [];

    public function __construct(protected string $appRoot) {}

    public function run(Deployment $deployment): Deployment
    {
        $this->ran[] = $deployment->id;

        if (in_array(strtolower((string) $deployment->sha), $this->failing, true)) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => 'health check failed (fake)',
                'finished_at' => now(),
            ]);

            return $deployment->refresh();
        }

        $current = $this->appRoot.DIRECTORY_SEPARATOR.'current';
        if (! is_dir($current)) {
            mkdir($current, 0777, true);
        }
        file_put_contents($current.DIRECTORY_SEPARATOR.'RELEASE_SHA', $deployment->sha."\n");

        $deployment->update(['status' => Deployment::STATUS_DEPLOYED, 'finished_at' => now()]);

        return $deployment->refresh();
    }
}
