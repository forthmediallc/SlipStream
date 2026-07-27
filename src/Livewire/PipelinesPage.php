<?php

namespace Slipstream\Livewire;

use Livewire\Attributes\Locked;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Support\Activity;

class PipelinesPage extends SlipstreamComponent
{
    public string $name = '';

    public string $branch_pattern = 'main';

    public bool $auto_deploy = true;

    public bool $enabled = true;

    /** @var list<string> */
    public array $selected_servers = [];

    public bool $all_servers = true;

    #[Locked]
    public ?int $editingId = null;

    public bool $showForm = false;

    public function newPipeline(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Pipeline::query()->findOrFail($id);
        $this->editingId = $p->id;
        $this->name = $p->name;
        $this->branch_pattern = $p->branch_pattern;
        $this->auto_deploy = (bool) $p->auto_deploy;
        $this->enabled = (bool) $p->enabled;
        $this->all_servers = $p->server_names === null || $p->server_names === [];
        $this->selected_servers = $p->server_names ?? [];
        $this->showForm = true;
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:120',
            'branch_pattern' => 'required|string|max:120',
            'auto_deploy' => 'boolean',
            'enabled' => 'boolean',
        ]);

        $serverNames = $this->all_servers ? null : array_values(array_filter($this->selected_servers));

        $data = [
            'name' => $this->name,
            'branch_pattern' => $this->branch_pattern,
            'auto_deploy' => $this->auto_deploy,
            'enabled' => $this->enabled,
            'server_names' => $serverNames,
        ];

        if ($this->editingId) {
            $pipeline = Pipeline::query()->findOrFail($this->editingId);
            $pipeline->update($data);
            Activity::log('pipeline.updated', ['name' => $data['name']]);
            session()->flash('slipstream_status', 'Pipeline updated.');
        } else {
            Pipeline::query()->create($data);
            Activity::log('pipeline.created', ['name' => $data['name']]);
            session()->flash('slipstream_status', 'Pipeline created.');
        }

        $this->resetForm();
    }

    public function toggleEnabled(int $id): void
    {
        $p = Pipeline::query()->findOrFail($id);
        $p->update(['enabled' => ! $p->enabled]);
        Activity::log('pipeline.'.($p->enabled ? 'enabled' : 'disabled'), ['name' => $p->name]);
    }

    public function toggleAutoDeploy(int $id): void
    {
        $p = Pipeline::query()->findOrFail($id);
        $p->update(['auto_deploy' => ! $p->auto_deploy]);
        Activity::log('pipeline.auto_deploy_'.($p->auto_deploy ? 'on' : 'off'), ['name' => $p->name]);
    }

    public function delete(int $id): void
    {
        $pipeline = Pipeline::query()->find($id);
        Pipeline::query()->whereKey($id)->delete();
        if ($this->editingId === $id) {
            $this->resetForm();
        }
        Activity::log('pipeline.deleted', ['name' => $pipeline?->name]);
        session()->flash('slipstream_status', 'Pipeline deleted.');
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->branch_pattern = 'main';
        $this->auto_deploy = true;
        $this->enabled = true;
        $this->all_servers = true;
        $this->selected_servers = [];
        $this->showForm = false;
    }

    public function render()
    {
        return view('slipstream::livewire.pipelines-page', [
            'pipelines' => Pipeline::query()->orderBy('name')->get(),
            'servers' => Server::query()->orderBy('server_name')->get(),
        ]);
    }
}
