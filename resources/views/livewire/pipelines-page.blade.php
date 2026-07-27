<div>
    @include('slipstream::livewire.partials.flash')
    <div class="ss-header">
        <div>
            <h1 class="ss-section-title" style="margin-bottom:0">Pipelines</h1>
            <p class="ss-muted ss-small">
                Branch/tag pattern → auto-deploy on/off and which servers participate.
                Patterns support globs (<code>main</code>, <code>release/*</code>).
            </p>
        </div>
        <div class="ss-actions">
            <button type="button" class="ss-btn ss-btn-primary" wire:click="newPipeline">
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/></svg>
                Add Pipeline
            </button>
        </div>
    </div>

    <div class="ss-card ss-card-flush">
        <table class="ss-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Branch pattern</th>
                    <th>Auto</th>
                    <th>Enabled</th>
                    <th>Servers</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($pipelines as $p)
                    <tr>
                        <td><strong>{{ $p->name }}</strong></td>
                        <td><code>{{ $p->branch_pattern }}</code></td>
                        <td>
                            <button type="button" class="ss-btn ss-btn-sm" wire:click="toggleAutoDeploy({{ $p->id }})">
                                {{ $p->auto_deploy ? 'on' : 'off' }}
                            </button>
                        </td>
                        <td>
                            <button type="button" class="ss-btn ss-btn-sm" wire:click="toggleEnabled({{ $p->id }})">
                                {{ $p->enabled ? 'on' : 'off' }}
                            </button>
                        </td>
                        <td class="ss-muted">
                            @if($p->server_names === null || $p->server_names === [])
                                All servers
                            @else
                                {{ implode(', ', $p->server_names) }}
                            @endif
                        </td>
                        <td>
                            <div class="ss-row-actions">
                                <button type="button" class="ss-icon-btn" wire:click="edit({{ $p->id }})" title="Edit" aria-label="Edit pipeline">
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                                </button>
                                <button type="button" class="ss-icon-btn ss-icon-btn-danger" wire:click="delete({{ $p->id }})"
                                        wire:confirm="Delete pipeline {{ $p->name }}?" title="Delete" aria-label="Delete pipeline">
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ss-muted">No pipelines. Create one (install seeds <code>main</code>).</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($showForm)
        <div class="ss-modal-overlay" wire:key="pipeline-modal">
            <div class="ss-modal">
                <div class="ss-modal-head">
                    <h2>{{ $editingId ? 'Edit pipeline' : 'Add pipeline' }}</h2>
                    <button type="button" class="ss-modal-close" wire:click="cancelEdit" aria-label="Close">&times;</button>
                </div>
                <form wire:submit="save">
                    <div class="ss-modal-body">
                        <div class="ss-grid-2">
                            <div class="ss-field">
                                <label>Name</label>
                                <input type="text" class="ss-input" wire:model="name" placeholder="production">
                                @error('name') <span class="ss-err">{{ $message }}</span> @enderror
                            </div>
                            <div class="ss-field">
                                <label>Branch pattern</label>
                                <input type="text" class="ss-input" wire:model="branch_pattern" placeholder="main">
                                @error('branch_pattern') <span class="ss-err">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="ss-field">
                            <label class="ss-check"><input type="checkbox" wire:model="auto_deploy"> Auto-deploy on matching push</label>
                        </div>
                        <div class="ss-field">
                            <label class="ss-check"><input type="checkbox" wire:model="enabled"> Enabled</label>
                        </div>
                        <div class="ss-field">
                            <label class="ss-check"><input type="checkbox" wire:model.live="all_servers"> All servers</label>
                        </div>
                        @unless($all_servers)
                            <div class="ss-field">
                                <label>Participating servers</label>
                                <div class="ss-check-grid">
                                    @forelse($servers as $s)
                                        <label class="ss-check">
                                            <input type="checkbox" value="{{ $s->server_name }}" wire:model="selected_servers">
                                            {{ $s->server_name }}
                                        </label>
                                    @empty
                                        <span class="ss-muted">No servers registered yet.</span>
                                    @endforelse
                                </div>
                            </div>
                        @endunless
                    </div>
                    <div class="ss-modal-foot">
                        <button type="button" class="ss-btn" wire:click="cancelEdit">Cancel</button>
                        <button type="submit" class="ss-btn ss-btn-primary">{{ $editingId ? 'Update' : 'Create' }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
