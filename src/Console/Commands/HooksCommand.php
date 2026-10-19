<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Deploy\DeploySteps;
use Slipstream\Deploy\HookFile;
use Slipstream\Deploy\MigrationCoordinator;
use Slipstream\Models\DeploymentHook;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

/**
 * The Hooks page from the shell: every real task of a deploy — build assets,
 * migrate, optimize, restart workers — is a hook attached before or after a
 * structural step, and this edits them the same way the page does.
 */
class HooksCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:hooks
                            {action=list : list | show | add | edit | enable | disable | delete | move}
                            {id? : Hook id (show, edit, enable, disable, delete, move)}
                            {direction? : up | down (move)}
                            {--step= : Step the hook attaches to: fetch, link_shared, composer, migrate, health, swap, fpm, prune}
                            {--position= : before | after that step (default: after)}
                            {--name= : Hook name}
                            {--script= : The script; the php, composer and npm placeholders (in double curly braces) expand to the configured binaries}
                            {--script-file= : Read the script from this file ("-" for stdin)}
                            {--servers= : Comma-separated server names the hook runs on; "all" for every server}
                            {--fleet-lock= : 1/0 — run on only one server per commit (migrations)}
                            {--force : Delete without asking}
                            {--json : Machine-readable output (list, show)}';

    protected $description = 'List and manage deploy hooks — the Hooks page';

    public function handle(): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list(),
                'show' => $this->show($this->hook()),
                'add' => $this->add(),
                'edit' => $this->edit($this->hook()),
                'enable' => $this->toggle($this->hook(), true),
                'disable' => $this->toggle($this->hook(), false),
                'delete' => $this->delete($this->hook()),
                'move' => $this->move($this->hook(), (string) $this->argument('direction')),
                default => $this->unknownAction(),
            };
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Every hook a deploy would consider, in run order and with its source:
     * the live release's hooks file first, then the database. Defaults that
     * step aside — for the file, or for the migrate step — are listed as such.
     */
    protected function list(): int
    {
        $releaseRoot = $this->liveReleaseRoot();
        $fileError = null;

        try {
            $fileHooks = HookFile::load($releaseRoot);
        } catch (\RuntimeException $e) {
            $fileHooks = null;
            $fileError = $e->getMessage();
        }

        $migrateStep = MigrationCoordinator::command() !== null;
        $skipped = function (DeploymentHook $hook) use ($fileHooks, $migrateStep): ?string {
            return match (true) {
                $fileHooks !== null && DefaultHooks::isUntouchedDefault($hook) => 'skipped: the hooks file replaces the defaults',
                $migrateStep && DefaultHooks::isUntouchedDefault($hook, DefaultHooks::MIGRATE_HOOK) => 'skipped: the migrate step runs migrations',
                default => null,
            };
        };

        $hooks = collect([...($fileHooks ?? []), ...DeploymentHook::query()->get()->all()])
            ->sortBy(fn (DeploymentHook $h) => [
                array_search($h->step, DeploySteps::STEPS, true),
                $h->position === 'before' ? 0 : 1,
                $h->source === 'file' ? 0 : 1,
                $h->sort_order,
                $h->id ?? 0,
            ])->values();

        if ($this->option('json')) {
            $this->json($hooks->map(fn (DeploymentHook $h) => $this->hookJson($h) + ['skipped' => $skipped($h)])->all());

            return self::SUCCESS;
        }

        if ($fileError !== null) {
            $this->components->error($fileError);
        }

        if ($hooks->isEmpty()) {
            $this->components->info('No hooks. Add one: slipstream:hooks add --step=composer --name="Build assets" --script="{{ npm }} run build"');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Source', 'On', 'Step', 'When', 'Name', 'Servers', 'Lock', 'Script'],
            $hooks->map(fn (DeploymentHook $h) => [
                $h->id ? '#'.$h->id : '—',
                $h->source,
                $h->enabled ? ($skipped($h) ? 'no' : 'yes') : 'no',
                $h->step,
                $h->position,
                $h->name.(($why = $skipped($h)) ? " ({$why})" : ''),
                $h->server_names ? implode(', ', $h->server_names) : 'all',
                $h->fleet_lock ? 'fleet' : '—',
                $this->truncate(strtok((string) $h->script, "\n") ?: '', 48).(str_contains((string) $h->script, "\n") ? ' …' : ''),
            ])->all()
        );
        if ($fileHooks !== null) {
            $this->line('  File hooks come from '.HookFile::path($releaseRoot).'; each deploy reads the file from the release it builds.');
        }
        $this->line('  Steps run in this order: '.implode(' → ', DeploySteps::STEPS).'. `slipstream:hooks show <id>` prints a full script.');

        return self::SUCCESS;
    }

    /**
     * The release that is live, for its hooks file: current/ in a release
     * layout, else this checkout.
     */
    protected function liveReleaseRoot(): string
    {
        $current = Slipstream::appRoot().DIRECTORY_SEPARATOR.'current';

        return is_dir($current) ? (realpath($current) ?: $current) : base_path();
    }

    protected function show(DeploymentHook $hook): int
    {
        if ($this->option('json')) {
            $this->json($this->hookJson($hook));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail("Hook #{$hook->id}", $hook->name);
        $this->components->twoColumnDetail('Runs', $hook->position.' '.$hook->step.' ('.DeploySteps::label($hook->step).')');
        $this->components->twoColumnDetail('Enabled', $hook->enabled ? 'yes' : 'no');
        $this->components->twoColumnDetail('Servers', $hook->server_names ? implode(', ', $hook->server_names) : 'all');
        $this->components->twoColumnDetail('Fleet lock', $hook->fleet_lock ? 'yes — one server per commit' : 'no');
        $this->components->twoColumnDetail('Order', (string) $hook->sort_order);
        $this->newLine();
        $this->line('<options=bold>Script</>');
        foreach (explode("\n", str_replace("\r\n", "\n", (string) $hook->script)) as $line) {
            $this->line('  '.htmlspecialchars($line, ENT_NOQUOTES));
        }

        return self::SUCCESS;
    }

    protected function add(): int
    {
        $data = $this->validated([
            'step' => (string) ($this->option('step') ?: ''),
            'position' => (string) ($this->option('position') ?: 'after'),
            'name' => (string) ($this->option('name') ?: ''),
            'script' => $this->script() ?? '',
        ]);

        $hook = DeploymentHook::query()->create($data + [
            'fleet_lock' => $this->flag('fleet-lock') ?? false,
            'server_names' => $this->serverNames('servers'),
            'enabled' => true,
            'sort_order' => (int) DeploymentHook::query()->max('sort_order') + 10,
        ]);

        Activity::log('hook.added', ['step' => $hook->step, 'position' => $hook->position, 'name' => $hook->name, 'via' => 'cli']);
        $this->components->info("Hook #{$hook->id} \"{$hook->name}\" added: {$hook->position} {$hook->step}.");

        return self::SUCCESS;
    }

    protected function edit(DeploymentHook $hook): int
    {
        $script = $this->script();

        $data = $this->validated([
            'step' => (string) ($this->option('step') ?: $hook->step),
            'position' => (string) ($this->option('position') ?: $hook->position),
            'name' => (string) ($this->option('name') ?: $hook->name),
            'script' => $script ?? (string) $hook->script,
        ]);

        if (($lock = $this->flag('fleet-lock')) !== null) {
            $data['fleet_lock'] = $lock;
        }
        if ($this->option('servers') !== null && $this->option('servers') !== '') {
            $data['server_names'] = $this->serverNames('servers');
        }

        $hook->update($data);

        Activity::log('hook.updated', ['id' => $hook->id, 'name' => $hook->name, 'via' => 'cli']);
        $this->components->info("Hook #{$hook->id} \"{$hook->name}\" updated.");

        return self::SUCCESS;
    }

    protected function toggle(DeploymentHook $hook, bool $enabled): int
    {
        $hook->update(['enabled' => $enabled]);

        Activity::log('hook.'.($enabled ? 'enabled' : 'disabled'), ['id' => $hook->id, 'name' => $hook->name, 'via' => 'cli']);
        $this->components->info("Hook #{$hook->id} \"{$hook->name}\" ".($enabled ? 'enabled' : 'disabled').'.');

        return self::SUCCESS;
    }

    protected function delete(DeploymentHook $hook): int
    {
        if (! $this->option('force') && ! $this->confirm("Delete hook #{$hook->id} \"{$hook->name}\"?")) {
            $this->line('Kept.');

            return self::SUCCESS;
        }

        $hook->delete();

        Activity::log('hook.deleted', ['id' => $hook->id, 'name' => $hook->name, 'via' => 'cli']);
        $this->components->info("Hook #{$hook->id} \"{$hook->name}\" deleted.");

        return self::SUCCESS;
    }

    /**
     * Swap order with the neighbour in the same step and position — the same
     * move the page's arrows make.
     */
    protected function move(DeploymentHook $hook, string $direction): int
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException('move needs a direction: up or down.');
        }

        $siblings = DeploymentHook::query()
            ->where('step', $hook->step)
            ->where('position', $hook->position)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $index = $siblings->search(fn (DeploymentHook $h) => $h->id === $hook->id);
        $swapWith = $direction === 'up' ? $siblings->get($index - 1) : $siblings->get($index + 1);

        if (! $swapWith) {
            $this->line("Hook #{$hook->id} is already ".($direction === 'up' ? 'first' : 'last')." in {$hook->position} {$hook->step}.");

            return self::SUCCESS;
        }

        $a = $hook->sort_order;
        $b = $swapWith->sort_order;
        if ($a === $b) {
            $b = $a + ($direction === 'up' ? -1 : 1);
        }
        $hook->update(['sort_order' => $b]);
        $swapWith->update(['sort_order' => $a]);

        Activity::log('hook.moved', ['id' => $hook->id, 'name' => $hook->name, 'direction' => $direction, 'via' => 'cli']);
        $this->components->info("Hook #{$hook->id} moved {$direction}, now ".($direction === 'up' ? 'before' : 'after')." #{$swapWith->id} \"{$swapWith->name}\".");

        return self::SUCCESS;
    }

    protected function hook(): DeploymentHook
    {
        $id = $this->argument('id');
        if ($id === null || ! ctype_digit((string) $id)) {
            throw new InvalidArgumentException('Give the hook id: slipstream:hooks '.$this->argument('action').' <id>. See `slipstream:hooks list`.');
        }

        return DeploymentHook::query()->find((int) $id)
            ?? throw new InvalidArgumentException("Hook #{$id} not found. See `slipstream:hooks list`.");
    }

    /**
     * --script, or --script-file (a path, or "-" for stdin). Null when neither.
     */
    protected function script(): ?string
    {
        $inline = $this->option('script');
        $file = $this->option('script-file');

        if (is_string($inline) && $inline !== '') {
            return $inline;
        }

        if (is_string($file) && $file !== '') {
            $contents = $file === '-' ? file_get_contents('php://stdin') : @file_get_contents($file);
            if ($contents === false) {
                throw new InvalidArgumentException("Could not read script file {$file}.");
            }

            return rtrim(str_replace("\r\n", "\n", $contents), "\n");
        }

        return null;
    }

    /**
     * The page's rules, verbatim.
     *
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    protected function validated(array $data): array
    {
        $validator = Validator::make($data, [
            'step' => 'required|in:'.implode(',', DeploySteps::STEPS),
            'position' => 'required|in:'.implode(',', DeploySteps::POSITIONS),
            'name' => 'required|string|max:120',
            'script' => 'required|string',
        ], [
            'step.required' => 'A step is required: --step='.implode('|', DeploySteps::STEPS),
            'step.in' => 'Unknown step; one of '.implode(', ', DeploySteps::STEPS),
            'position.in' => '--position must be before or after',
            'name.required' => 'A name is required: --name="…"',
            'script.required' => 'A script is required: --script="…" or --script-file=path',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function hookJson(DeploymentHook $hook): array
    {
        return [
            'id' => $hook->id,
            'source' => $hook->source,
            'enabled' => (bool) $hook->enabled,
            'step' => $hook->step,
            'position' => $hook->position,
            'name' => $hook->name,
            'servers' => $hook->server_names,
            'fleet_lock' => (bool) $hook->fleet_lock,
            'sort_order' => $hook->sort_order,
            'script' => $hook->script,
        ];
    }

    protected function unknownAction(): int
    {
        $this->components->error('Unknown action "'.$this->argument('action').'". Use: list, show, add, edit, enable, disable, delete, move.');

        return self::FAILURE;
    }
}
