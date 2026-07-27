<div @if($pollingMs > 0) wire:poll.{{ max($pollingMs, 5000) }}ms @endif>
    @include('slipstream::livewire.partials.flash')
    <div class="ss-header">
        <div>
            <h1 class="ss-section-title" style="margin-bottom:0">Servers</h1>
            <p class="ss-muted ss-small">
                Self-registered fleet (no SSH). Heartbeats every minute via
                <code>slipstream:heartbeat</code>. Stale after {{ $staleAfter }}s without a pulse.
            </p>
        </div>
    </div>

    @if($summary['has_drift'])
        <div class="ss-alert ss-alert-err">
            <strong>Drift detected.</strong>
            Canonical SHA <code>{{ $drift->short($summary['canonical_sha']) }}</code>
            — {{ $summary['stale']->count() }} server(s) on a different release.
        </div>
    @endif

    @if($summary['offline']->isNotEmpty())
        <div class="ss-alert ss-alert-warn">
            <strong>{{ $summary['offline']->count() }} server(s) offline / stale</strong>
            — no heartbeat within {{ $staleAfter }}s: {{ $summary['offline']->pluck('server_name')->join(', ') }}
        </div>
    @endif

    <div class="ss-card ss-card-flush">
        <table class="ss-table">
            <thead>
                <tr>
                    <th>Server</th>
                    <th>SHA</th>
                    <th>Status</th>
                    <th>Disk</th>
                    <th>PHP</th>
                    <th>Services</th>
                    <th>Heartbeat</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($summary['servers'] as $server)
                    @php
                        $isStale = $summary['stale']->contains('id', $server->id);
                        $isOffline = $summary['offline']->contains('id', $server->id);
                        $isThis = $server->server_name === $thisServer;
                    @endphp
                    <tr class="{{ $isStale ? 'ss-row-drift' : '' }} {{ $isOffline ? 'ss-row-offline' : '' }}">
                        <td>
                            <strong>{{ $server->server_name }}</strong>
                            @if($isThis) <span class="ss-badge">this node</span> @endif
                            @if($server->hostname)
                                <div class="ss-muted ss-small">{{ $server->hostname }}</div>
                            @endif
                        </td>
                        <td>
                            <code class="ss-commit-link" style="cursor:default">{{ $drift->short($server->current_sha) }}</code>
                            @if($isStale)
                                <span class="ss-badge ss-badge-failed">drift</span>
                            @elseif($summary['canonical_sha'] && $server->current_sha === $summary['canonical_sha'])
                                <span class="ss-badge ss-badge-deployed">current</span>
                            @endif
                        </td>
                        <td>
                            @if(! $server->enabled)
                                <span class="ss-badge">disabled</span>
                            @elseif($server->paused)
                                <span class="ss-badge ss-badge-building">paused</span>
                            @elseif($isOffline)
                                <span class="ss-status ss-status-failed">
                                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                                    Offline
                                </span>
                            @else
                                <span class="ss-status ss-status-deployed">
                                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                    Online
                                </span>
                            @endif
                            @if(! $server->run_migrations)
                                <div class="ss-muted ss-small">migrations off</div>
                            @endif
                        </td>
                        <td class="ss-muted">
                            @if($server->disk_free_bytes)
                                {{ number_format($server->disk_free_bytes / 1024 / 1024 / 1024, 1) }} GB free
                            @else
                                —
                            @endif
                        </td>
                        <td class="ss-muted">{{ $server->php_version ?: '—' }}</td>
                        <td class="ss-muted ss-small">
                            FPM {{ $server->fpm_service ?: '—' }}<br>
                            Horizon {{ $server->horizon_running === null ? '?' : ($server->horizon_running ? 'up' : 'down') }}
                            · Reverb {{ $server->reverb_running === null ? '?' : ($server->reverb_running ? 'up' : 'down') }}
                        </td>
                        <td class="ss-muted">{{ $server->last_heartbeat_at?->diffForHumans() ?? 'never' }}</td>
                        <td>
                            <div class="ss-dropdown" wire:key="srv-actions-{{ $server->id }}"
                                 x-data="{ open: false }"
                                 @click.outside="open = false"
                                 @keydown.escape.window="open = false">
                                <button type="button" class="ss-dropdown-toggle" :class="{ 'is-open': open }"
                                        @click="open = ! open" :aria-expanded="open"
                                        aria-haspopup="true" aria-label="Server actions">⋯</button>
                                <div class="ss-dropdown-menu" x-show="open" x-cloak @click="open = false">
                                    <button type="button" wire:click="togglePause({{ $server->id }})">{{ $server->paused ? 'Resume' : 'Pause' }}</button>
                                    <button type="button" wire:click="toggleEnabled({{ $server->id }})">{{ $server->enabled ? 'Disable' : 'Enable' }}</button>
                                    <button type="button" wire:click="edit({{ $server->id }})">Edit settings</button>
                                    @unless($isThis)
                                        <hr>
                                        <button type="button" class="ss-danger" wire:click="remove({{ $server->id }})"
                                                wire:confirm="Remove {{ $server->server_name }} from the registry?">Remove from fleet</button>
                                    @endunless
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="ss-muted">
                            No servers yet. Heartbeats register nodes automatically
                            (<code>php artisan slipstream:heartbeat</code> or open this page).
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($editingId)
        <div class="ss-card" style="margin-top:1.25rem">
            <h2>Per-server settings</h2>
            <div class="ss-field">
                <label>Base URL (for webhook relay fan-out)</label>
                <input type="url" class="ss-input" wire:model="base_url" placeholder="https://web-2.example.com">
                @error('base_url') <span class="ss-err">{{ $message }}</span> @enderror
                <p class="ss-muted ss-small">Directly addressable origin (no path). Required on peers when relay mode is on.</p>
            </div>
            <div class="ss-grid-3">
                <div class="ss-field">
                    <label>FPM service</label>
                    <input type="text" class="ss-input" wire:model="fpm_service">
                </div>
                <div class="ss-field">
                    <label>Horizon program</label>
                    <input type="text" class="ss-input" wire:model="horizon_program">
                </div>
                <div class="ss-field">
                    <label>Reverb program</label>
                    <input type="text" class="ss-input" wire:model="reverb_program">
                </div>
            </div>
            <div class="ss-field">
                <label class="ss-check">
                    <input type="checkbox" wire:model="run_migrations">
                    Eligible to run fleet migrations (only one server per SHA actually migrates)
                </label>
            </div>
            <div class="ss-actions">
                <button type="button" class="ss-btn ss-btn-primary" wire:click="saveSettings">Save</button>
                <button type="button" class="ss-btn" wire:click="cancelEdit">Cancel</button>
            </div>
        </div>
    @endif

    <div class="ss-info" style="margin-top:1.25rem">
        <p><strong>Multi-server webhooks</strong></p>
        <p class="ss-muted">
            <strong>Without relay:</strong> one provider webhook per server (directly addressable URL).
            A load balancer would only hit one node. Same secret on every server (shared DB).
        </p>
        <p class="ss-muted">
            <strong>With relay:</strong> one webhook → this admin node → signed fan-out to each peer’s
            <code>base_url</code> (set above). Enable relay under Webhook settings.
        </p>
        <p class="ss-muted" style="margin-bottom:0">
            This node’s endpoint:
            <code>{{ url('/'.\Slipstream\Slipstream::path().'/hooks/deploy') }}</code>
        </p>
    </div>
</div>
