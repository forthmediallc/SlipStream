<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Models\Deployment;
use Slipstream\Slipstream;

/**
 * The Deployments page as a table: newest first, filterable by what you are
 * actually looking for — what is running, what failed, what one server did.
 */
class DeploymentsCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:deployments
                            {--status=* : Only these statuses: queued, pending, building, deployed, failed, rolled-back, superseded}
                            {--active : Only in-flight rows (queued, pending, building)}
                            {--failed : Only failed rows}
                            {--server= : Only this server ("this" = this node)}
                            {--limit=20 : Rows to show}
                            {--json : Machine-readable output}';

    protected $description = 'List deployments, newest first — the Deployments page';

    public const STATUSES = [
        Deployment::STATUS_QUEUED,
        Deployment::STATUS_PENDING,
        Deployment::STATUS_BUILDING,
        Deployment::STATUS_DEPLOYED,
        Deployment::STATUS_FAILED,
        Deployment::STATUS_ROLLED_BACK,
        Deployment::STATUS_SUPERSEDED,
    ];

    public function handle(): int
    {
        $statuses = array_map('strtolower', array_filter((array) $this->option('status')));
        if ($this->option('active')) {
            $statuses = [...$statuses, Deployment::STATUS_QUEUED, Deployment::STATUS_PENDING, Deployment::STATUS_BUILDING];
        }
        if ($this->option('failed')) {
            $statuses[] = Deployment::STATUS_FAILED;
        }
        $statuses = array_values(array_unique($statuses));

        $unknown = array_diff($statuses, self::STATUSES);
        if ($unknown !== []) {
            $this->components->error('Unknown status: '.implode(', ', $unknown).'. Known: '.implode(', ', self::STATUSES));

            return self::FAILURE;
        }

        $query = Deployment::query()->latest('id');
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $server = (string) $this->option('server');
        if ($server !== '') {
            $query->where('server_name', $server === 'this' ? Slipstream::serverName() : $server);
        }

        $limit = max(1, (int) $this->option('limit'));
        $total = (clone $query)->count();
        $deployments = $query->limit($limit)->get();

        if ($this->option('json')) {
            $this->json([
                'total' => $total,
                'deployments' => $deployments->map(fn (Deployment $d) => $this->deploymentJson($d))->values()->all(),
            ]);

            return self::SUCCESS;
        }

        if ($deployments->isEmpty()) {
            $this->components->info('No deployments match.');

            return self::SUCCESS;
        }

        $this->table($this->deploymentHeaders, $deployments->map(fn (Deployment $d) => $this->deploymentRow($d))->all());

        if ($total > $deployments->count()) {
            $this->line("  Showing {$deployments->count()} of {$total} — raise --limit or narrow with --status/--server.");
        }

        return self::SUCCESS;
    }
}
