<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Deploy\Deployer;
use Slipstream\Deploy\EventLogger;
use Slipstream\Models\Deployment;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;

class DeployCommand extends Command
{
    protected $signature = 'slipstream:deploy
                            {--deployment= : Existing deployment id}
                            {--sha= : Commit SHA to deploy (default: branch tip)}
                            {--branch= : Branch name}
                            {--trigger=manual : Trigger label}';

    protected $description = 'Run a Slipstream deploy (detached from webhook/dashboard, or manually)';

    public function handle(SettingsRepository $settings): int
    {
        $deployment = $this->resolveDeployment($settings);

        $this->info("Deploy #{$deployment->id} starting on ".Slipstream::serverName().'…');

        $logger = new EventLogger($deployment);
        $deployer = new Deployer($deployment, $settings, $logger);

        try {
            $deployer->run();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $deployment->refresh();
        if ($deployment->status === Deployment::STATUS_DEPLOYED) {
            $this->info('Deploy finished: '.$deployment->shortSha());

            return self::SUCCESS;
        }

        // Coalesced is recorded as failed with reason — not a hard process failure.
        $this->warn($deployment->failure_reason ?: $deployment->status);

        return self::SUCCESS;
    }

    protected function resolveDeployment(SettingsRepository $settings): Deployment
    {
        if ($id = $this->option('deployment')) {
            $deployment = Deployment::query()->find($id);
            if (! $deployment) {
                throw new \InvalidArgumentException("Deployment [{$id}] not found.");
            }

            return $deployment;
        }

        return Deployment::query()->create([
            'server_name' => Slipstream::serverName(),
            'sha' => $this->option('sha') ?: null,
            'branch' => $this->option('branch') ?: $settings->branch(),
            'trigger' => $this->option('trigger') ?: Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_PENDING,
        ]);
    }
}
