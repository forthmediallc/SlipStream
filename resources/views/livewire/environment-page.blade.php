<div>
    @include('slipstream::livewire.partials.flash')
    <div class="ss-header">
        <div>
            <h1 class="ss-section-title" style="margin-bottom:0">Environment</h1>
            <p class="ss-muted ss-small">
                Edit the <strong>shared</strong> <code>.env</code> for this layout
                (<code>{{ $path }}</code> on <code>{{ $serverName }}</code>).
                This is not release-local. A bad save can take the app down — confirm before saving.
            </p>
        </div>
    </div>

    @php
        $statusBadge = [
            'pending' => 'ss-badge',
            'applying' => 'ss-badge ss-badge-building',
            'applied' => 'ss-badge ss-badge-deployed',
            'failed' => 'ss-badge ss-badge-failed',
            'superseded' => 'ss-badge',
            'cancelled' => 'ss-badge',
        ];
        $keysList = implode(', ', $perServerKeys);
    @endphp

    @unless($syncEnabled)
        {{-- Env sync disabled: this-server-only editor (legacy behavior). --}}
        <div class="ss-alert ss-alert-err">
            <strong>Danger zone.</strong> Writes the shared env on <strong>this server only</strong>
            (multi-server env sync is disabled via <code>SLIPSTREAM_ENV_SYNC</code>). Previous file is copied to
            <code>.env.slipstream.bak.&lt;timestamp&gt;</code>. Config cache is cleared if present;
            production should re-run <code>config:cache</code> via the next deploy’s optimize step.
        </div>

        @if($loadError)
            <div class="ss-alert ss-alert-err">{{ $loadError }}</div>
            <button type="button" class="ss-btn" wire:click="reload">Retry load</button>
        @else
            <form wire:submit="save" class="ss-card">
                <div class="ss-field">
                    <label>Shared .env contents</label>
                    <textarea class="ss-input ss-mono ss-env-editor" rows="24" wire:model="contents" spellcheck="false"></textarea>
                    @error('contents') <span class="ss-err">{{ $message }}</span> @enderror
                </div>
                <div class="ss-field">
                    <label>Change note (optional, audit log)</label>
                    <input type="text" class="ss-input" wire:model="note" placeholder="Rotated Stripe keys">
                </div>
                <div class="ss-field">
                    <label class="ss-check">
                        <input type="checkbox" wire:model="confirmDanger">
                        I understand this can break production if values are wrong
                    </label>
                    @error('confirmDanger') <span class="ss-err">{{ $message }}</span> @enderror
                </div>
                <div class="ss-actions">
                    <button type="submit" class="ss-btn ss-btn-danger">Save shared .env</button>
                    <button type="button" class="ss-btn" wire:click="reload">Reload from disk</button>
                </div>
            </form>
        @endif
    @else
        {{-- Scope selector --}}
        <div class="ss-card">
            <div class="ss-field">
                <label>Scope</label>
                <select class="ss-input" wire:model.live="mode">
                    <option value="single">A single server</option>
                    <option value="custom">Selected servers</option>
                    <option value="fleet">The whole fleet</option>
                </select>
            </div>

            @if($mode === 'single')
                <div class="ss-field">
                    <label>Server</label>
                    <select class="ss-input" wire:model.live="editServer">
                        @foreach($servers as $s)
                            @php $hasSnap = $s->server_name === $serverName || $snapshots->has($s->server_name); @endphp
                            <option value="{{ $s->server_name }}">
                                {{ $s->server_name }}@if($s->server_name === $serverName) (this node)@elseif(! $hasSnap) (no snapshot yet)@endif
                            </option>
                        @endforeach
                    </select>
                    <p class="ss-muted ss-small">
                        Editing one server writes exactly what you save (per-server keys are not merged).
                    </p>
                </div>
            @elseif($mode === 'custom')
                <div class="ss-field">
                    <label>Target servers</label>
                    <div class="ss-grid-3">
                        @foreach($servers as $s)
                            @php $online = $s->isHeartbeatFresh($staleAfter); $snap = $snapshots->get($s->server_name); @endphp
                            <label class="ss-check" wire:key="pick-{{ $s->id }}">
                                <input type="checkbox" wire:model="selectedServers" value="{{ $s->server_name }}">
                                <span>
                                    {{ $s->server_name }}
                                    @if($s->server_name === $serverName) <span class="ss-badge">this</span> @endif
                                    @if(! $s->enabled) <span class="ss-badge">disabled</span>
                                    @elseif($online) <span class="ss-badge ss-badge-deployed">online</span>
                                    @else <span class="ss-badge ss-badge-failed">offline</span> @endif
                                    <span class="ss-muted ss-small">
                                        {{ $snap ? 'env '.$snap->captured_at?->diffForHumans() : 'no snapshot' }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('selectedServers') <span class="ss-err">{{ $message }}</span> @enderror
                    <p class="ss-muted ss-small">
                        Base content is taken from this node; per-server keys ({{ $keysList ?: 'none' }}) keep each target’s own value.
                    </p>
                </div>
            @else
                <p class="ss-muted ss-small" style="margin-bottom:0">
                    Pushes to <strong>all {{ $servers->where('enabled', true)->count() }} enabled server(s)</strong>.
                    Base content is taken from this node; per-server keys ({{ $keysList ?: 'none' }}) keep each node’s own value.
                </p>
            @endif
        </div>

        {{-- Remote source banner --}}
        @if($mode === 'single' && $remoteSource)
            @php $target = $servers->firstWhere('server_name', $editServer); $snap = $snapshots->get($editServer); @endphp
            <div class="ss-alert ss-alert-warn">
                Showing <strong>{{ $editServer }}</strong>’s file from its last snapshot
                ({{ $snap?->captured_at?->diffForHumans() ?? 'unknown' }}).
                @if($target && ! $target->isHeartbeatFresh($staleAfter))
                    That server looks offline — the push waits in its queue until it returns.
                @endif
            </div>
        @endif

        {{-- Env drift --}}
        @if(count($driftServers))
            <div class="ss-alert ss-alert-warn">
                <strong>Env drift.</strong> Ignoring per-server keys, these server(s) differ from this node:
                {{ implode(', ', $driftServers) }}.
            </div>
        @endif

        {{-- Scope-aware danger copy --}}
        <div class="ss-alert ss-alert-err">
            <strong>Danger zone.</strong>
            @if($mode === 'single' && $editServer === $serverName)
                Writes this server’s shared <code>.env</code> now.
            @elseif($mode === 'single')
                Queues an exact-content update to <strong>{{ $editServer }}</strong>; it applies on that node
                (instantly if reachable, else within a minute of its heartbeat).
            @else
                Queues a full-file replace to each target. Per-server keys
                (<code>{{ $keysList ?: 'none' }}</code>) are preserved from each node’s own file.
            @endif
            A bad value can take the target(s) down. Each write backs up to
            <code>.env.slipstream.bak.&lt;timestamp&gt;</code> on the target.
        </div>

        @if($loadError)
            <div class="ss-alert ss-alert-err">{{ $loadError }}</div>
            <button type="button" class="ss-btn" wire:click="reload">Retry load</button>
        @else
            <form wire:submit="save" class="ss-card">
                <div class="ss-field">
                    <label>
                        {{ $mode === 'single' ? '.env contents for '.$editServer : '.env base content (from '.$serverName.')' }}
                    </label>
                    <textarea class="ss-input ss-mono ss-env-editor" rows="22" wire:model="contents" spellcheck="false"></textarea>
                    @error('contents') <span class="ss-err">{{ $message }}</span> @enderror
                </div>
                <div class="ss-field">
                    <label>Change note (optional, audit log)</label>
                    <input type="text" class="ss-input" wire:model="note" placeholder="Rotated Stripe keys">
                </div>
                <div class="ss-field">
                    <label class="ss-check">
                        <input type="checkbox" wire:model="confirmDanger">
                        I understand this can break production if values are wrong
                    </label>
                    @error('confirmDanger') <span class="ss-err">{{ $message }}</span> @enderror
                </div>
                <div class="ss-actions">
                    <button type="submit" class="ss-btn ss-btn-danger">
                        @if($mode === 'single' && $editServer === $serverName) Save shared .env
                        @elseif($mode === 'single') Queue to {{ $editServer }}
                        @elseif($mode === 'fleet') Queue to fleet
                        @else Queue to selected @endif
                    </button>
                    <button type="button" class="ss-btn" wire:click="reload">Reload</button>
                </div>
            </form>
        @endif

        {{-- Push status (polled) --}}
        <div wire:poll.5000ms>
            <h2 class="ss-section-title" style="margin-top:0.5rem">Push status</h2>
            <div class="ss-card ss-card-flush">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Server</th>
                            <th>Status</th>
                            <th>When</th>
                            <th>Detail</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pushes as $p)
                            <tr wire:key="push-{{ $p->id }}">
                                <td class="ss-small"><code>{{ substr($p->batch, 0, 8) }}</code></td>
                                <td>{{ $p->server_name }}</td>
                                <td><span class="{{ $statusBadge[$p->status] ?? 'ss-badge' }}">{{ $p->status }}</span></td>
                                <td class="ss-muted ss-small">
                                    @if($p->status === 'applied')
                                        applied {{ $p->applied_at?->diffForHumans() }}
                                    @elseif($p->status === 'pending')
                                        queued {{ $p->created_at?->diffForHumans() }}
                                    @else
                                        {{ $p->updated_at?->diffForHumans() }}
                                    @endif
                                </td>
                                <td class="ss-muted ss-small">{{ $p->error ?: ($p->note ?: '—') }}</td>
                                <td>
                                    @if($p->status === 'pending')
                                        <button type="button" class="ss-btn ss-btn-sm" wire:click="cancelPush({{ $p->id }})"
                                                wire:confirm="Cancel the pending push to {{ $p->server_name }}?">Cancel</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ss-muted">No pushes yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endunless

    <h2 class="ss-section-title" style="margin-top:0.5rem">Recent changes</h2>
    <div class="ss-card ss-card-flush">
        <table class="ss-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>User</th>
                    <th>Server</th>
                    <th>Bytes</th>
                    <th>Backup</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                @forelse($audits as $a)
                    <tr>
                        <td class="ss-muted">{{ $a->created_at?->diffForHumans() }}</td>
                        <td>{{ $a->user_id ?: '—' }}</td>
                        <td>{{ $a->server_name ?: '—' }}</td>
                        <td>{{ $a->bytes ? number_format($a->bytes) : '—' }}</td>
                        <td class="ss-small"><code>{{ $a->backup_path ? basename($a->backup_path) : '—' }}</code></td>
                        <td class="ss-muted">{{ $a->note ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ss-muted">No edits yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
