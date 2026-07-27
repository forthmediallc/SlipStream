<div>
    @include('slipstream::livewire.partials.flash')

    <div class="ss-header">
        <div>
            <h1 class="ss-section-title" style="margin-bottom:0">Deployment Info</h1>
        </div>
    </div>

    <div class="ss-info">
        <p>
            Deployments may be triggered in three ways: manually using <strong>Deploy</strong>, by a push to a matching pipeline branch, or by a signed request to the webhook URL:
        </p>
        <div class="ss-info-url">{{ url('/'.\Slipstream\Slipstream::path().'/hooks/deploy') }}</div>
        <p class="ss-muted ss-small" style="margin-top:0.35rem">
            Configure the shared secret on the <a href="{{ route('slipstream.webhook') }}">Webhook</a> page. Optional
            <code>sha</code> or <code>branch</code> query parameters override what Slipstream will deploy when using the HTTP trigger path from your git host payload.
        </p>
    </div>

    <div class="ss-header" style="margin-top:0.25rem">
        <div>
            <h1 class="ss-section-title" style="margin-bottom:0">Deployment Hooks</h1>
            <p class="ss-muted ss-small">
                Pipeline for <code>{{ $serverName }}</code> in run order. Locked rows are structural; everything else is editable.
            </p>
        </div>
        <div class="ss-actions">
            <button type="button" class="ss-btn ss-btn-primary" wire:click="newHook">
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/></svg>
                Add Hook
            </button>
        </div>
    </div>

    <div class="ss-card ss-card-flush">
        <table class="ss-table ss-hooks-table">
            <thead>
                <tr>
                    <th style="width:48px"></th>
                    <th>Action</th>
                    <th style="width:100px">Run As</th>
                    <th>Servers</th>
                    <th style="width:96px"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($stepMeta as $key => $meta)
                    @foreach($hooksBy[$key.'|before'] ?? [] as $hook)
                        @include('slipstream::livewire.partials.hook-row', ['hook' => $hook])
                    @endforeach

                    <tr class="ss-step-row" wire:key="step-{{ $key }}">
                        <td title="Built-in step">
                            <svg viewBox="0 0 16 16" fill="currentColor" width="14" height="14" style="opacity:.45" aria-hidden="true"><path fill-rule="evenodd" d="M8 1a3.5 3.5 0 00-3.5 3.5V7H3.75A1.75 1.75 0 002 8.75v4.5c0 .966.784 1.75 1.75 1.75h8.5A1.75 1.75 0 0014 13.25v-4.5A1.75 1.75 0 0012.25 7H11.5V4.5A3.5 3.5 0 008 1zm2 6V4.5a2 2 0 10-4 0V7h4z"/></svg>
                        </td>
                        <td>
                            <strong>{{ $meta[0] }}</strong>
                            <div class="ss-muted ss-small">{{ $meta[1] }}</div>
                        </td>
                        <td><span class="ss-dash">—</span></td>
                        <td><span class="ss-dash">—</span></td>
                        <td></td>
                    </tr>

                    @foreach($hooksBy[$key.'|after'] ?? [] as $hook)
                        @include('slipstream::livewire.partials.hook-row', ['hook' => $hook])
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="ss-muted ss-small">
        Scripts run through <code>sh</code> with the release directory as cwd.
        Placeholders: <code>@{{ release }}</code>, <code>@{{ sha }}</code>, <code>@{{ branch }}</code>, <code>@{{ php }}</code>, <code>@{{ npm }}</code>.
        A <span class="ss-tag ss-tag-lock">fleet lock</span> hook runs on only one server per commit — leave it on for migrations.
    </p>

    @if($showHookForm)
        <div class="ss-modal-overlay" wire:key="hook-modal">
            <div class="ss-modal">
                <div class="ss-modal-head">
                    <h2>{{ $editingHookId ? 'Update hook' : 'Add hook' }}</h2>
                    <button type="button" class="ss-modal-close" wire:click="cancelHook" aria-label="Close">&times;</button>
                </div>
                <form wire:submit="saveHook">
                    <div class="ss-modal-body">
                        <div class="ss-field">
                            <label>Name</label>
                            <input type="text" class="ss-input" wire:model="hook_name" placeholder="Build assets">
                            @error('hook_name') <span class="ss-err">{{ $message }}</span> @enderror
                        </div>
                        <div class="ss-grid-3">
                            <div class="ss-field">
                                <label>Step</label>
                                <select class="ss-input" wire:model="hook_step">
                                    @foreach(\Slipstream\Livewire\DeployHooksPage::STEP_META as $s => $m)
                                        <option value="{{ $s }}">{{ $m[0] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="ss-field">
                                <label>When</label>
                                <select class="ss-input" wire:model="hook_position">
                                    <option value="before">before</option>
                                    <option value="after">after</option>
                                </select>
                            </div>
                        </div>
                        <div class="ss-field">
                            <label>Script</label>
                            <textarea class="ss-input ss-mono" rows="6" wire:model="hook_script" placeholder="cd @{{ release }}&#10;@{{ npm }} run build"></textarea>
                            @error('hook_script') <span class="ss-err">{{ $message }}</span> @enderror
                        </div>
                        <div class="ss-field">
                            <label class="ss-check"><input type="checkbox" wire:model="hook_fleet_lock"> Run on only one server per commit <span class="ss-tag ss-tag-lock">fleet lock</span></label>
                            <div class="ss-muted ss-small" style="margin-top:0.3rem">The first eligible server to grab the fleet-wide lock runs it; the rest skip. Use for database migrations.</div>
                        </div>
                        <div class="ss-field">
                            <label class="ss-check"><input type="checkbox" wire:model.live="hook_all_servers"> Run on all servers</label>
                        </div>
                        @unless($hook_all_servers)
                            <div class="ss-field">
                                <label>On servers</label>
                                <div class="ss-check-grid">
                                    @forelse($servers as $s)
                                        <label class="ss-check"><input type="checkbox" value="{{ $s->server_name }}" wire:model="hook_server_names"> {{ $s->server_name }}</label>
                                    @empty
                                        <span class="ss-muted">No servers registered yet.</span>
                                    @endforelse
                                </div>
                            </div>
                        @endunless
                    </div>
                    <div class="ss-modal-foot">
                        <button type="button" class="ss-btn" wire:click="cancelHook">Cancel</button>
                        <button type="submit" class="ss-btn ss-btn-primary">{{ $editingHookId ? 'Save hook' : 'Add hook' }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
