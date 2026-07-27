<?php

namespace Slipstream\Livewire;

use Livewire\Attributes\Locked;
use Slipstream\Models\DeploymentHook;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

/**
 * The deploy pipeline in one place (Envoyer "Deployment Hooks"): the structural
 * steps are shown locked for context, and every real task — build, migrate,
 * optimize, restart workers — is an editable hook you can reorder, disable, or
 * delete. Hooks attach before/after a structural step.
 */
class DeployHooksPage extends SlipstreamComponent
{
    public bool $showHookForm = false;

    #[Locked]
    public ?int $editingHookId = null;

    public string $hook_step = 'composer';

    public string $hook_position = 'after';

    public string $hook_name = '';

    public string $hook_script = '';

    public bool $hook_fleet_lock = false;

    /** @var list<string> */
    public array $hook_server_names = [];

    public bool $hook_all_servers = true;

    /** Structural steps a hook can attach to (the locked skeleton). */
    public const STEPS = ['fetch', 'link_shared', 'composer', 'health', 'swap', 'fpm', 'prune'];

    /** Ordered built-in steps: key => [label, what it does]. */
    public const STEP_META = [
        'fetch' => ['Clone new release', 'git archive of the target commit into a fresh release dir'],
        'link_shared' => ['Link shared paths', '.env, storage/, and any shared directories'],
        'composer' => ['Install Composer dependencies', 'composer install --no-dev --optimize-autoloader'],
        'health' => ['Health check', 'php artisan slipstream:healthcheck — gates the swap'],
        'swap' => ['Activate new release', 'atomic symlink swap: current → new release'],
        'fpm' => ['Reload PHP-FPM', 'graceful systemctl reload (connections kept)'],
        'prune' => ['Purge old releases', 'keep the newest N releases'],
    ];

    public function newHook(): void
    {
        $this->resetHookForm();
        $this->showHookForm = true;
    }

    public function editHook(int $id): void
    {
        $hook = DeploymentHook::query()->findOrFail($id);
        $this->editingHookId = $hook->id;
        $this->hook_step = $hook->step;
        $this->hook_position = $hook->position;
        $this->hook_name = $hook->name;
        $this->hook_script = $hook->script;
        $this->hook_fleet_lock = (bool) $hook->fleet_lock;
        $this->hook_all_servers = $hook->server_names === null || $hook->server_names === [];
        $this->hook_server_names = $hook->server_names ?? [];
        $this->showHookForm = true;
    }

    public function saveHook(): void
    {
        $this->validate([
            'hook_step' => 'required|in:'.implode(',', self::STEPS),
            'hook_position' => 'required|in:before,after',
            'hook_name' => 'required|string|max:120',
            'hook_script' => 'required|string',
        ]);

        $servers = $this->hook_all_servers ? null : array_values(array_filter($this->hook_server_names));

        $attributes = [
            'step' => $this->hook_step,
            'position' => $this->hook_position,
            'name' => $this->hook_name,
            'script' => $this->hook_script,
            'fleet_lock' => $this->hook_fleet_lock,
            'server_names' => $servers,
        ];

        if ($this->editingHookId) {
            $hook = DeploymentHook::query()->findOrFail($this->editingHookId);
            $hook->update($attributes);
            Activity::log('hook.updated', ['id' => $hook->id, 'name' => $hook->name]);
            session()->flash('slipstream_status', 'Hook updated.');
        } else {
            $hook = DeploymentHook::query()->create($attributes + [
                'enabled' => true,
                'sort_order' => (int) DeploymentHook::query()->max('sort_order') + 10,
            ]);
            Activity::log('hook.added', ['step' => $hook->step, 'position' => $hook->position, 'name' => $hook->name]);
            session()->flash('slipstream_status', 'Hook added.');
        }

        $this->resetHookForm();
        $this->showHookForm = false;
    }

    public function toggleHook(int $id): void
    {
        $hook = DeploymentHook::query()->findOrFail($id);
        $hook->update(['enabled' => ! $hook->enabled]);
        Activity::log('hook.'.($hook->enabled ? 'enabled' : 'disabled'), ['id' => $id, 'name' => $hook->name]);
    }

    public function deleteHook(int $id): void
    {
        $hook = DeploymentHook::query()->find($id);
        DeploymentHook::query()->where('id', $id)->delete();
        Activity::log('hook.deleted', ['id' => $id, 'name' => $hook?->name]);
        session()->flash('slipstream_status', 'Hook deleted.');
    }

    /**
     * Swap a hook's order with its neighbour in the same step + position.
     */
    public function moveHook(int $id, string $direction): void
    {
        $hook = DeploymentHook::query()->findOrFail($id);

        $siblings = DeploymentHook::query()
            ->where('step', $hook->step)
            ->where('position', $hook->position)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $index = $siblings->search(fn ($h) => $h->id === $hook->id);
        $swapWith = $direction === 'up' ? $siblings->get($index - 1) : $siblings->get($index + 1);

        if (! $swapWith) {
            return;
        }

        $a = $hook->sort_order;
        $b = $swapWith->sort_order;
        // Ensure distinct order values before swapping.
        if ($a === $b) {
            $b = $a + ($direction === 'up' ? -1 : 1);
        }
        $hook->update(['sort_order' => $b]);
        $swapWith->update(['sort_order' => $a]);
    }

    public function cancelHook(): void
    {
        $this->resetHookForm();
        $this->showHookForm = false;
    }

    protected function resetHookForm(): void
    {
        $this->editingHookId = null;
        $this->hook_step = 'composer';
        $this->hook_position = 'after';
        $this->hook_name = '';
        $this->hook_script = '';
        $this->hook_fleet_lock = false;
        $this->hook_all_servers = true;
        $this->hook_server_names = [];
    }

    public function render()
    {
        return view('slipstream::livewire.deploy-hooks-page', [
            'stepMeta' => self::STEP_META,
            'hooksBy' => DeploymentHook::query()->orderBy('sort_order')->orderBy('id')->get()
                ->groupBy(fn ($h) => $h->step.'|'.$h->position),
            'servers' => \Slipstream\Models\Server::query()->orderBy('server_name')->get(),
            'serverName' => Slipstream::serverName(),
        ]);
    }
}
