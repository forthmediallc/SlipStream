<?php

namespace Slipstream\Livewire;

use Livewire\Attributes\Locked;
use Slipstream\Environment\EnvEditor;
use Slipstream\Environment\EnvSync;
use Slipstream\Models\EnvAudit;
use Slipstream\Models\EnvPush;
use Slipstream\Models\EnvSnapshot;
use Slipstream\Models\Server;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

class EnvironmentPage extends SlipstreamComponent
{
    public string $contents = '';

    public bool $confirmDanger = false;

    public string $note = '';

    /** Scope: single (one server) | fleet (all enabled) | custom (selected). */
    public string $mode = 'single';

    /** Server whose file is loaded/edited in single mode. */
    public string $editServer = '';

    /** @var list<string> Target server names in custom mode. */
    public array $selectedServers = [];

    #[Locked]
    public bool $loaded = false;

    #[Locked]
    public ?string $loadError = null;

    /** Hash of the loaded content's source, for optimistic-concurrency on apply. */
    #[Locked]
    public ?string $baseHash = null;

    /** @var array<string, ?string> server_name => snapshot hash, captured at load for fleet/custom. */
    #[Locked]
    public array $remoteBaseHashes = [];

    /** True when the loaded content came from a peer's snapshot (not local disk). */
    #[Locked]
    public bool $remoteSource = false;

    public function mount(): void
    {
        $this->editServer = Slipstream::serverName();
        $this->loadTarget();
    }

    public function updatedMode(): void
    {
        $this->loadTarget();
    }

    public function updatedEditServer(): void
    {
        if ($this->mode === 'single') {
            $this->loadTarget();
        }
    }

    /**
     * Load the content + base hash for the current scope/target into the editor.
     */
    protected function loadTarget(): void
    {
        $this->loaded = false;
        $this->loadError = null;
        $this->remoteSource = false;
        $this->baseHash = null;
        $this->remoteBaseHashes = [];

        $editor = app(EnvEditor::class);
        $self = Slipstream::serverName();

        try {
            // Single mode targeting a peer: load that node's encrypted snapshot.
            if ($this->mode === 'single' && $this->editServer !== '' && $this->editServer !== $self) {
                if (! EnvSync::enabled()) {
                    $this->loadError = 'Env sync is disabled — only this server can be edited.';

                    return;
                }

                $snapshot = EnvSnapshot::query()->where('server_name', $this->editServer)->first();
                if (! $snapshot) {
                    $this->loadError = 'No snapshot yet for '.$this->editServer
                        .'. It publishes on that node\'s next heartbeat (after it upgrades Slipstream, or if env sync is enabled there).';

                    return;
                }

                try {
                    $this->contents = (string) $snapshot->contents;
                } catch (\Throwable) {
                    $this->loadError = 'Cannot decrypt '.$this->editServer.'\'s snapshot — its APP_KEY differs from this node. '
                        .'A shared APP_KEY is required across the fleet.';

                    return;
                }

                $this->baseHash = $snapshot->hash;
                $this->remoteSource = true;
                $this->loaded = true;

                return;
            }

            // Self / fleet / custom: base content is this node's shared .env on disk.
            if (! $editor->exists()) {
                $this->loadError = 'Shared .env not found at '.$editor->path()
                    .' — create it on the server first, then reload this page.';

                return;
            }

            $this->contents = $editor->read();
            $this->baseHash = EnvSync::hashContents($this->contents);
            $this->loaded = true;

            if ($this->mode !== 'single' && EnvSync::enabled()) {
                $this->remoteBaseHashes = EnvSnapshot::query()->pluck('hash', 'server_name')->all();
            }
        } catch (\Throwable $e) {
            $this->loadError = $e->getMessage();
        }
    }

    public function save(EnvSync $sync): void
    {
        if (! $this->confirmDanger) {
            $this->addError('confirmDanger', 'Check the confirmation box before saving.');

            return;
        }

        // Sync off → write this server's file directly, with no shared-DB row.
        // The off-switch exists partly for policies that forbid .env blobs (even
        // encrypted) in the database, so this path must not create an EnvPush.
        if (! EnvSync::enabled()) {
            $this->saveLocalOnly();

            return;
        }

        $rules = [
            'contents' => 'required|string|max:1048576',
            'note' => 'nullable|string|max:255',
        ];
        if ($this->mode === 'custom') {
            $rules['selectedServers'] = 'required|array|min:1';
        }
        $this->validate($rules);

        $self = Slipstream::serverName();

        // Resolve targets + per-target base hashes for the chosen scope.
        if ($this->mode === 'single') {
            $target = $this->editServer !== '' ? $this->editServer : $self;
            $targets = [$target];
            $baseHashes = [$target => $this->baseHash];
            $preserveKeys = false;
        } else {
            $targets = $this->mode === 'fleet'
                ? Server::query()->where('enabled', true)->pluck('server_name')->all()
                : array_values(array_intersect($this->selectedServers, Server::query()->pluck('server_name')->all()));

            if ($targets === []) {
                session()->flash('slipstream_error', 'No valid target servers selected.');

                return;
            }

            $baseHashes = [];
            foreach ($targets as $t) {
                $baseHashes[$t] = ($t === $self) ? $this->baseHash : ($this->remoteBaseHashes[$t] ?? null);
            }
            $preserveKeys = true;
        }

        try {
            $result = $sync->queue($targets, $this->contents, [
                'preserve_keys' => $preserveKeys,
                'base_hashes' => $baseHashes,
                'user_id' => auth()->id(),
                'note' => $this->note !== '' ? $this->note : null,
            ]);

            $this->confirmDanger = false;
            $this->note = '';

            session()->flash('slipstream_status', $this->savedMessage($result, $targets, $self, $sync));

            // Reflect any local disk change and refresh base hashes.
            $this->loadTarget();
        } catch (\Throwable $e) {
            session()->flash('slipstream_error', $e->getMessage());
        }
    }

    /**
     * Legacy this-server-only write when env sync is disabled — no shared-DB row,
     * identical to the pre-fleet editor.
     */
    protected function saveLocalOnly(): void
    {
        $this->validate([
            'contents' => 'required|string|max:1048576',
            'note' => 'nullable|string|max:255',
        ]);

        $editor = app(EnvEditor::class);

        try {
            $result = $editor->write($this->contents, auth()->id(), $this->note !== '' ? $this->note : null);
            $editor->invalidateConfigCache();
            Activity::log('env.saved', ['bytes' => $result['bytes']]);

            $this->confirmDanger = false;
            $this->note = '';
            session()->flash(
                'slipstream_status',
                'Shared .env saved ('.number_format($result['bytes']).' bytes).'
                .($result['backup_path'] ? ' Backup: '.basename($result['backup_path']).'.' : '')
                .' Re-run config:cache on next deploy if you cache config.'
            );

            $this->loadTarget();
        } catch (\Throwable $e) {
            session()->flash('slipstream_error', $e->getMessage());
        }
    }

    public function cancelPush(int $id): void
    {
        $push = EnvPush::query()->find($id);
        if (! $push) {
            return;
        }

        $affected = EnvPush::query()
            ->whereKey($id)
            ->where('status', EnvPush::STATUS_PENDING)
            ->update(['status' => EnvPush::STATUS_CANCELLED]);

        if ($affected === 0) {
            session()->flash('slipstream_status', 'That push was already picked up or is no longer pending.');

            return;
        }

        Activity::log('env.push_cancelled', ['server' => $push->server_name, 'batch' => $push->batch]);
        session()->flash('slipstream_status', 'Cancelled the pending push to '.$push->server_name.'.');
    }

    public function reload(): void
    {
        $this->loadTarget();
    }

    /**
     * @param  array{batch: string, queued: int, local: ?EnvPush}  $result
     * @param  list<string>  $targets
     */
    protected function savedMessage(array $result, array $targets, string $self, EnvSync $sync): string
    {
        $bytes = number_format(strlen($this->contents));

        if ($this->mode === 'single') {
            $target = $targets[0];

            if ($target === $self) {
                $local = $result['local'];
                if ($local && $local->status === EnvPush::STATUS_APPLIED) {
                    return "Shared .env saved on {$self} ({$bytes} bytes). Re-run config:cache on next deploy if you cache config.";
                }
                if ($local && $local->status === EnvPush::STATUS_FAILED) {
                    return "Save failed on {$self}: ".$local->error;
                }

                return "Queued for {$self}.";
            }

            return "Queued for {$target} — applies instantly if reachable, otherwise within a minute of its next heartbeat.";
        }

        $keys = implode(', ', $sync->perServerKeys());

        return 'Queued for '.$result['queued'].' server(s) — each applies instantly when reachable, otherwise within a minute of its heartbeat.'
            .($keys !== '' ? " Per-server keys ({$keys}) keep each node's own value." : '');
    }

    public function render(EnvEditor $editor)
    {
        $syncEnabled = EnvSync::enabled();
        $self = Slipstream::serverName();
        $perServerKeys = app(EnvSync::class)->perServerKeys();

        $servers = $syncEnabled
            ? Server::query()->orderBy('server_name')->get()
            : collect();
        $snapshots = $syncEnabled
            ? EnvSnapshot::query()->get()->keyBy('server_name')
            : collect();
        $pushes = $syncEnabled
            ? EnvPush::query()->latest('id')->limit(30)->get()
            : collect();

        return view('slipstream::livewire.environment-page', [
            'path' => $editor->path(),
            'serverName' => $self,
            'audits' => EnvAudit::query()->latest('id')->limit(20)->get(),
            'servers' => $servers,
            'snapshots' => $snapshots,
            'pushes' => $pushes,
            'driftServers' => $this->driftServers($syncEnabled, $self, $servers, $snapshots, $perServerKeys),
            'perServerKeys' => $perServerKeys,
            'syncEnabled' => $syncEnabled,
            'staleAfter' => (int) config('slipstream.fleet.heartbeat_stale_seconds', 120),
            'pollingMs' => (int) config('slipstream.realtime.polling_ms', 1500),
        ]);
    }

    /**
     * Enabled peers whose env (ignoring per-server keys) differs from this node.
     *
     * @param  \Illuminate\Support\Collection<int, Server>  $servers
     * @param  \Illuminate\Support\Collection<string, EnvSnapshot>  $snapshots
     * @param  list<string>  $perServerKeys
     * @return list<string>
     */
    protected function driftServers(bool $syncEnabled, string $self, $servers, $snapshots, array $perServerKeys): array
    {
        if (! $syncEnabled) {
            return [];
        }

        // Baseline: this node's own snapshot, else the loaded (non-remote) content.
        $selfContents = null;
        if (isset($snapshots[$self])) {
            try {
                $selfContents = (string) $snapshots[$self]->contents;
            } catch (\Throwable) {
                $selfContents = null;
            }
        }
        if ($selfContents === null && $this->loaded && ! $this->remoteSource) {
            $selfContents = $this->contents;
        }
        if ($selfContents === null) {
            return [];
        }

        $selfComparable = EnvSync::comparableHash($selfContents, $perServerKeys);
        $enabled = $servers->where('enabled', true)->pluck('server_name')->all();

        $drift = [];
        foreach ($snapshots as $name => $snap) {
            if ($name === $self || ! in_array($name, $enabled, true)) {
                continue;
            }
            try {
                if (EnvSync::comparableHash((string) $snap->contents, $perServerKeys) !== $selfComparable) {
                    $drift[] = $name;
                }
            } catch (\Throwable) {
                // Undecryptable snapshot — not a drift signal.
            }
        }

        return $drift;
    }
}
