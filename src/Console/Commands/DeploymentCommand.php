<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentEvent;
use Slipstream\Slipstream;

/**
 * One deployment with its log — the deployment page. `--follow` tails a
 * running one the way the page polls, and exits non-zero if it fails, so a
 * script can chain `slipstream:deploy --fleet` and wait on the outcome.
 */
class DeploymentCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:deployment
                            {id? : Deployment id (default: the newest on this node)}
                            {--tail=100 : Log lines to show (0 = all)}
                            {--follow : Keep printing new log lines until the deployment finishes}
                            {--json : Machine-readable output (details and log)}';

    protected $description = 'Show one deployment and its log — the deployment page; --follow tails a running one';

    public function handle(): int
    {
        $deployment = $this->resolve();
        if (! $deployment) {
            return self::FAILURE;
        }

        $tail = max(0, (int) $this->option('tail'));
        $events = $this->events($deployment, $tail);

        if ($this->option('json')) {
            $this->json([
                'deployment' => $this->deploymentJson($deployment),
                'events' => $events->map(fn (DeploymentEvent $e) => [
                    'sequence' => $e->sequence,
                    'type' => $e->type,
                    'step' => $e->step,
                    'level' => $e->level,
                    'message' => $e->message,
                    'at' => $e->created_at?->toIso8601String(),
                ])->values()->all(),
            ]);

            return $deployment->status === Deployment::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
        }

        $this->renderDetails($deployment);

        $this->newLine();
        $total = DeploymentEvent::query()->where('deployment_id', $deployment->id)->count();
        $this->line('<options=bold>Log</>'.($tail > 0 && $total > $events->count() ? " (last {$events->count()} of {$total}; --tail=0 for all)" : ''));
        $events->each(fn (DeploymentEvent $e) => $this->renderEvent($e));

        $last = (int) ($events->last()?->sequence ?? 0);

        if ($this->option('follow')) {
            $deployment = $this->follow($deployment, $last);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Outcome', $this->outcome($deployment));

        return $deployment->status === Deployment::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }

    protected function resolve(): ?Deployment
    {
        $id = $this->argument('id');

        if ($id !== null) {
            $deployment = Deployment::query()->find((int) $id);
            if (! $deployment) {
                $this->components->error("Deployment #{$id} not found.");
            }

            return $deployment;
        }

        $deployment = Deployment::query()
            ->where('server_name', Slipstream::serverName())
            ->latest('id')
            ->first();

        if (! $deployment) {
            $this->components->error('No deployments on '.Slipstream::serverName().' yet.');
        }

        return $deployment;
    }

    /**
     * @return Collection<int, DeploymentEvent>
     */
    protected function events(Deployment $deployment, int $tail): Collection
    {
        $query = DeploymentEvent::query()->where('deployment_id', $deployment->id);

        if ($tail === 0) {
            return $query->orderBy('sequence')->get();
        }

        return $query->orderByDesc('sequence')->limit($tail)->get()->sortBy('sequence')->values();
    }

    protected function renderDetails(Deployment $deployment): void
    {
        $this->components->twoColumnDetail("Deployment #{$deployment->id}", $deployment->status);
        $this->components->twoColumnDetail('Server', $deployment->server_name);
        $this->components->twoColumnDetail('Trigger', $deployment->trigger.($deployment->initiator_user_id ? ' by user #'.$deployment->initiator_user_id : ''));
        $this->components->twoColumnDetail('Ref', ($deployment->branch ?: '—').' @ '.$this->shortSha($deployment->sha).($deployment->deployFrom() === 'tag' ? ' (tag)' : ''));
        if ($deployment->commit_message) {
            $this->components->twoColumnDetail('Commit', $this->truncate($deployment->commit_message, 70).($deployment->commit_author ? ' — '.$deployment->commit_author : ''));
        }
        if ($deployment->pipeline_id) {
            $this->components->twoColumnDetail('Pipeline', '#'.$deployment->pipeline_id);
        }
        if ($deployment->fleetBatch()) {
            $this->components->twoColumnDetail('Fleet batch', $deployment->fleetBatch().' from '.($deployment->meta['queued_from'] ?? '?'));
        }
        if ($deployment->skippedHookIds() !== []) {
            $this->components->twoColumnDetail('Skipped hooks', '#'.implode(', #', $deployment->skippedHookIds()));
        }
        $this->components->twoColumnDetail('Created', $deployment->created_at?->toDateTimeString().' ('.$this->ago($deployment->created_at).')');
        if ($deployment->started_at) {
            $this->components->twoColumnDetail('Started', $deployment->started_at->toDateTimeString());
        }
        if ($deployment->finished_at) {
            $this->components->twoColumnDetail('Finished', $deployment->finished_at->toDateTimeString().' · took '.$this->duration($deployment->duration_ms));
        }
        if ($deployment->release_path) {
            $this->components->twoColumnDetail('Release', $deployment->release_path);
        }
        if ($deployment->failure_reason) {
            $this->components->twoColumnDetail('<fg=red>Failure</>', $deployment->failure_reason);
        }
        if ($deployment->warnings) {
            $this->components->twoColumnDetail('<fg=yellow>Warnings</>', $deployment->warnings);
        }
    }

    protected function renderEvent(DeploymentEvent $event): void
    {
        $time = $event->created_at?->format('H:i:s') ?? '--:--:--';
        $step = $event->step ? '['.$event->step.'] ' : '';
        $message = htmlspecialchars((string) $event->message, ENT_NOQUOTES);

        $line = match (true) {
            $event->level === 'error' => "<fg=red>{$step}{$message}</>",
            $event->level === 'warning' => "<fg=yellow>{$step}{$message}</>",
            $event->type === 'step_start' || $event->type === 'step_end' => "<options=bold>{$step}{$message}</>",
            default => $step.$message,
        };

        $this->line("<fg=gray>{$time}</> {$line}");
    }

    /**
     * Poll the way the page does until the row reaches a terminal status.
     */
    protected function follow(Deployment $deployment, int $lastSequence): Deployment
    {
        while (! $this->terminal($deployment)) {
            usleep(1_000_000);

            DeploymentEvent::query()
                ->where('deployment_id', $deployment->id)
                ->where('sequence', '>', $lastSequence)
                ->orderBy('sequence')
                ->get()
                ->each(function (DeploymentEvent $e) use (&$lastSequence) {
                    $this->renderEvent($e);
                    $lastSequence = (int) $e->sequence;
                });

            $deployment->refresh();
        }

        return $deployment;
    }

    protected function terminal(Deployment $deployment): bool
    {
        return $deployment->isFinished() || $deployment->status === Deployment::STATUS_SUPERSEDED;
    }

    protected function outcome(Deployment $deployment): string
    {
        return match ($deployment->status) {
            Deployment::STATUS_DEPLOYED => '<fg=green>deployed</> — serving '.$this->shortSha($deployment->sha),
            Deployment::STATUS_ROLLED_BACK => '<fg=green>rolled back</> to '.$this->shortSha($deployment->sha),
            Deployment::STATUS_FAILED => '<fg=red>failed</> — '.$this->truncate($deployment->failure_reason, 120),
            Deployment::STATUS_SUPERSEDED => 'superseded — covered by the deploy that held the lock',
            default => $deployment->status.' (still running; --follow waits for it)',
        };
    }
}
