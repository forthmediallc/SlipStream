<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Models\Pipeline;
use Slipstream\Support\Activity;

/**
 * The Pipelines page from the shell: which branch patterns deploy on push,
 * automatically or not, and on which servers.
 */
class PipelinesCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:pipelines
                            {action=list : list | add | edit | enable | disable | delete}
                            {pipeline? : Pipeline id or name}
                            {--name= : Pipeline name}
                            {--branch= : Branch pattern: main, release/*, or * for everything}
                            {--servers= : Comma-separated server names; "all" for every server}
                            {--auto-deploy= : 1/0 — deploy automatically when a matching push arrives}
                            {--force : Delete without asking}
                            {--json : Machine-readable output (list)}';

    protected $description = 'List and manage pipelines — the Pipelines page';

    public function handle(): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list(),
                'add' => $this->add(),
                'edit' => $this->edit($this->pipeline()),
                'enable' => $this->toggle($this->pipeline(), true),
                'disable' => $this->toggle($this->pipeline(), false),
                'delete' => $this->delete($this->pipeline()),
                default => $this->unknownAction(),
            };
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function list(): int
    {
        $pipelines = Pipeline::query()->orderBy('name')->get();

        if ($this->option('json')) {
            $this->json($pipelines->map(fn (Pipeline $p) => $this->pipelineJson($p))->all());

            return self::SUCCESS;
        }

        if ($pipelines->isEmpty()) {
            $this->components->warn('No pipelines — every push is ignored. Add one: slipstream:pipelines add --name=main --branch=main');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Branch pattern', 'Auto-deploy', 'Enabled', 'Servers'],
            $pipelines->map(fn (Pipeline $p) => [
                '#'.$p->id, $p->name, $p->branch_pattern,
                $p->auto_deploy ? 'yes' : 'no', $p->enabled ? 'yes' : 'no',
                $p->server_names ? implode(', ', $p->server_names) : 'all',
            ])->all()
        );

        return self::SUCCESS;
    }

    protected function add(): int
    {
        $data = $this->validated([
            'name' => (string) ($this->option('name') ?: ''),
            'branch_pattern' => (string) ($this->option('branch') ?: ''),
        ]);

        $pipeline = Pipeline::query()->create($data + [
            'auto_deploy' => $this->flag('auto-deploy') ?? true,
            'enabled' => true,
            'server_names' => $this->serverNames('servers'),
        ]);

        Activity::log('pipeline.created', ['name' => $pipeline->name, 'via' => 'cli']);
        $this->components->info("Pipeline #{$pipeline->id} \"{$pipeline->name}\" added for {$pipeline->branch_pattern}.");

        return self::SUCCESS;
    }

    protected function edit(Pipeline $pipeline): int
    {
        $data = $this->validated([
            'name' => (string) ($this->option('name') ?: $pipeline->name),
            'branch_pattern' => (string) ($this->option('branch') ?: $pipeline->branch_pattern),
        ]);

        if (($auto = $this->flag('auto-deploy')) !== null) {
            $data['auto_deploy'] = $auto;
        }
        if ($this->option('servers') !== null && $this->option('servers') !== '') {
            $data['server_names'] = $this->serverNames('servers');
        }

        $pipeline->update($data);

        Activity::log('pipeline.updated', ['name' => $pipeline->name, 'via' => 'cli']);
        $this->components->info("Pipeline #{$pipeline->id} \"{$pipeline->name}\" updated.");

        return self::SUCCESS;
    }

    protected function toggle(Pipeline $pipeline, bool $enabled): int
    {
        $pipeline->update(['enabled' => $enabled]);

        Activity::log('pipeline.'.($enabled ? 'enabled' : 'disabled'), ['name' => $pipeline->name, 'via' => 'cli']);
        $this->components->info("Pipeline \"{$pipeline->name}\" ".($enabled ? 'enabled' : 'disabled').'.');

        return self::SUCCESS;
    }

    protected function delete(Pipeline $pipeline): int
    {
        if (! $this->option('force') && ! $this->confirm("Delete pipeline \"{$pipeline->name}\"? Pushes matching {$pipeline->branch_pattern} will be ignored.")) {
            $this->line('Kept.');

            return self::SUCCESS;
        }

        $pipeline->delete();

        Activity::log('pipeline.deleted', ['name' => $pipeline->name, 'via' => 'cli']);
        $this->components->info("Pipeline \"{$pipeline->name}\" deleted.");

        return self::SUCCESS;
    }

    protected function pipeline(): Pipeline
    {
        $ref = $this->argument('pipeline');
        if ($ref === null || $ref === '') {
            throw new InvalidArgumentException('Give the pipeline id or name: slipstream:pipelines '.$this->argument('action').' <id|name>.');
        }

        $pipeline = ctype_digit((string) $ref)
            ? Pipeline::query()->find((int) $ref)
            : Pipeline::query()->where('name', $ref)->first();

        return $pipeline ?? throw new InvalidArgumentException("No pipeline \"{$ref}\". See `slipstream:pipelines list`.");
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    protected function validated(array $data): array
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:120',
            'branch_pattern' => 'required|string|max:120',
        ], [
            'name.required' => 'A name is required: --name=main',
            'branch_pattern.required' => 'A branch pattern is required: --branch=main (or release/*, or *)',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function pipelineJson(Pipeline $pipeline): array
    {
        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'branch_pattern' => $pipeline->branch_pattern,
            'auto_deploy' => (bool) $pipeline->auto_deploy,
            'enabled' => (bool) $pipeline->enabled,
            'servers' => $pipeline->server_names,
        ];
    }

    protected function unknownAction(): int
    {
        $this->components->error('Unknown action "'.$this->argument('action').'". Use: list, add, edit, enable, disable, delete.');

        return self::FAILURE;
    }
}
