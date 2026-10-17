<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Deploy\Deployer;
use Slipstream\Deploy\EventLogger;
use Slipstream\Deploy\FailedReleases;
use Slipstream\Models\Deployment;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\AuditLog;

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

        $this->trapInterrupts();

        $this->info("Deploy #{$deployment->id} starting on ".Slipstream::serverName().'…');

        $logger = new EventLogger($deployment);

        try {
            // Constructing the Deployer resolves the PHP binary and can throw;
            // inside the try so an unusable SLIPSTREAM_PHP is recorded on the
            // row like any other failure instead of stranding it in "pending".
            $deployer = new Deployer($deployment, $settings, $logger);
            $deployer->run();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->recordUnrecordedFailure($deployment, $logger, $e);

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
    protected function trapInterrupts(): void
    {
        $this->trap(fn () => [SIGINT, SIGTERM], function (int $signal) {
            $this->markInterrupted($signal);

            exit(128 + $signal);
        });
    }

    /**
     * Fail every in-flight deploy row on this server and preserve its
     * half-built release, exactly as Deployer::fail() would have.
     *
     * By server rather than by the row the command started with: a catch-up
     * deploy chained by Deployer::maybeCatchUp() runs in this same process
     * under a NEW row, and the trap was registered before that row existed.
     * The flock guarantees at most one in-flight deploy per server, so this
     * targets precisely the run the signal killed.
     *
     * Everything is best-effort inside a try: an async signal handler can fire
     * between any two VM instructions, including with the deploy's own DB
     * connection mid-conversation — if recording fails, the every-minute
     * watchdog sweep is the backstop, and exiting promptly matters more.
     */
    public function markInterrupted(int $signal): void
    {
        try {
            $inFlight = Deployment::query()
                ->where('server_name', Slipstream::serverName())
                ->whereIn('status', [Deployment::STATUS_PENDING, Deployment::STATUS_BUILDING])
                ->get();

            foreach ($inFlight as $deployment) {
                $reason = "Interrupted by operator (signal {$signal}) — the deploy process was killed mid-run.";

                $failed = Deployment::query()
                    ->whereKey($deployment->id)
                    ->whereIn('status', [Deployment::STATUS_PENDING, Deployment::STATUS_BUILDING])
                    ->update([
                        'status' => Deployment::STATUS_FAILED,
                        'failure_reason' => $reason,
                        'finished_at' => now(),
                    ]);

                // A query-builder update fires no model event; a killed deploy
                // is exactly what an audit trail must show.
                if ($failed > 0) {
                    AuditLog::record('deployment.failed', ['deployment' => $deployment->id, 'reason' => $reason, 'via' => 'signal']);
                }

                $this->markReleaseFailed($deployment->release_path);
            }
        } catch (\Throwable) {
            // A signal handler has nowhere useful to report to; the watchdog
            // sweep records the failure within the minute.
        }
    }

    /**
     * Rename the half-built release the interrupted deploy left behind.
     *
     * Without this the tree stays in releases/ under a name indistinguishable
     * from a good release: it consumes a keep-N slot, blocks
     * `provision --adopt` (which reads a populated releases/ as "layout already
     * in use"), and is selectable as a rollback target whenever `current`
     * cannot be resolved. Deployer::fail() marks its own failures this way; a
     * killed process has to do it here or not at all.
     */
    protected function markReleaseFailed(?string $release): void
    {
        if (! $release || FailedReleases::isFailed($release) || ! is_dir($release)) {
            return;
        }

        // Never touch the tree that is being served: an interrupt after the
        // swap leaves current pointing at this release.
        $current = Slipstream::appRoot().DIRECTORY_SEPARATOR.'current';
        if ((realpath($current) ?: '') === (realpath($release) ?: $release)) {
            return;
        }

        if ((int) config('slipstream.deploy.keep_failed_releases', 1) < 1) {
            return;
        }

        @rename($release, $release.FailedReleases::SUFFIX);
    }

    /**
     * Deployer::run() records its own failures before rethrowing; anything that
     * escaped before run() started (construction, e.g. an unusable
     * SLIPSTREAM_PHP) has not been, and would otherwise sit in "pending" until
     * the watchdog guessed at a cause.
     */
    protected function recordUnrecordedFailure(Deployment $deployment, EventLogger $logger, \Throwable $e): void
    {
        $deployment->refresh();

        if ($deployment->isFinished() || $deployment->status === Deployment::STATUS_SUPERSEDED) {
            return;
        }

        $logger->error('DEPLOY FAILED: '.$e->getMessage());
        $deployment->update([
            'status' => Deployment::STATUS_FAILED,
            'failure_reason' => $e->getMessage(),
            'finished_at' => now(),
        ]);
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
