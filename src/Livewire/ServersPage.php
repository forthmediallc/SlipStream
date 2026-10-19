<?php

namespace Slipstream\Livewire;

use Livewire\Attributes\Locked;
use Slipstream\Fleet\Drift;
use Slipstream\Fleet\NodeHold;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Models\EnvPush;
use Slipstream\Models\EnvSnapshot;
use Slipstream\Models\Server;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

class ServersPage extends SlipstreamComponent
{
    #[Locked]
    public ?int $editingId = null;

    public string $fpm_service = '';

    public string $horizon_program = '';

    public string $reverb_program = '';

    public string $base_url = '';

    public bool $run_migrations = true;

    public function mount(Heartbeat $heartbeat): void
    {
        // Ensure this node is visible as soon as someone opens the page.
        $heartbeat->pulse();
    }

    public function togglePause(int $id): void
    {
        $server = Server::query()->findOrFail($id);

        // Resuming also lifts a poll-mode pin; it is a pause with a reason.
        if ($server->paused) {
            app(NodeHold::class)->resume($server);
        } else {
            $server->update(['paused' => true]);
        }

        Activity::log('server.'.($server->paused ? 'paused' : 'resumed'), ['server' => $server->server_name]);
        session()->flash(
            'slipstream_status',
            $server->paused
                ? "{$server->server_name} paused — webhooks accepted but builds skipped."
                : "{$server->server_name} resumed."
        );
    }

    public function toggleEnabled(int $id): void
    {
        $server = Server::query()->findOrFail($id);
        $server->update(['enabled' => ! $server->enabled]);
        Activity::log('server.'.($server->enabled ? 'enabled' : 'disabled'), ['server' => $server->server_name]);
    }

    public function edit(int $id): void
    {
        $server = Server::query()->findOrFail($id);
        $this->editingId = $server->id;
        $this->fpm_service = (string) ($server->fpm_service ?: config('slipstream.services.fpm'));
        $this->horizon_program = (string) ($server->horizon_program ?: config('slipstream.services.horizon'));
        $this->reverb_program = (string) ($server->reverb_program ?: config('slipstream.services.reverb'));
        $this->base_url = (string) ($server->base_url ?? '');
        $this->run_migrations = (bool) $server->run_migrations;
    }

    public function saveSettings(): void
    {
        $this->validate([
            'base_url' => 'nullable|url|max:500',
        ]);

        $server = Server::query()->findOrFail($this->editingId);

        $server->update([
            'fpm_service' => $this->fpm_service !== '' ? $this->fpm_service : null,
            'horizon_program' => $this->horizon_program !== '' ? $this->horizon_program : null,
            'reverb_program' => $this->reverb_program !== '' ? $this->reverb_program : null,
            'base_url' => $this->base_url !== '' ? rtrim($this->base_url, '/') : null,
            'run_migrations' => $this->run_migrations,
        ]);

        Activity::log('server.settings_saved', ['server' => $server->server_name]);
        $this->editingId = null;
        session()->flash('slipstream_status', "Settings saved for {$server->server_name}.");
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    public function remove(int $id): void
    {
        $server = Server::query()->findOrFail($id);
        if ($server->server_name === Slipstream::serverName()) {
            session()->flash('slipstream_error', 'Cannot remove this node from itself.');

            return;
        }
        $name = $server->server_name;
        $server->delete();

        // Drop that node's env snapshot + any queued pushes so a removed server
        // leaves nothing behind in the shared tables.
        EnvSnapshot::query()->where('server_name', $name)->delete();
        EnvPush::query()->where('server_name', $name)->delete();

        Activity::log('server.removed', ['server' => $name]);
        session()->flash('slipstream_status', "Removed {$name} from the fleet registry.");
    }

    public function render(Drift $drift)
    {
        $staleAfter = (int) config('slipstream.fleet.heartbeat_stale_seconds', 120);
        $summary = $drift->summary($staleAfter);

        return view('slipstream::livewire.servers-page', [
            'summary' => $summary,
            'drift' => $drift,
            'thisServer' => Slipstream::serverName(),
            'staleAfter' => $staleAfter,
            // Drives the edit modal; a row removed from under an open modal
            // resolves to null and simply closes it.
            'editingServer' => $this->editingId ? Server::query()->find($this->editingId) : null,
            'pollingMs' => (int) config('slipstream.realtime.polling_ms', 1500),
            // Poll mode takes run-migrations from SLIPSTREAM_RUN_MIGRATIONS only.
            'pollMode' => Slipstream::pollMode(),
        ]);
    }
}
