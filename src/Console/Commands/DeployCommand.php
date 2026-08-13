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

        $this->trapInterrupts($deployment);

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

    /**
     * Record the truth when a deploy is killed at the keyboard or by a
     * shutting-down supervisor.
     *
     * SIGINT kills PHP outright, so neither the catch below nor Deployer::fail()
     * ever runs and the row stays "building" on the dashboard until a watchdog
     * sweep notices. The deploy lock needs no explicit release: it is an flock
     * held on a file descriptor, so it goes when the process does.
     *
     * The signal list is a closure because SIGINT/SIGTERM are only defined when
     * ext-pcntl is loaded; trap() evaluates it lazily and skips the whole thing
     * where signals are unsupported (Windows).
     */
    protected function trapInterrupts(Deployment $deployment): void
    {
        $this->trap(fn () => [SIGINT, SIGTERM], function (int $signal) use ($deployment) {
            Deployment::query()
                ->whereKey($deployment->id)
                ->whereIn('status', [Deployment::STATUS_PENDING, Deployment::STATUS_BUILDING])
                ->update([
                    'status' => Deployment::STATUS_FAILED,
                    'failure_reason' => "Interrupted by operator (signal {$signal}) — the deploy process was killed mid-run.",
                    'finished_at' => now(),
                ]);

            exit(128 + $signal);
        });
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
