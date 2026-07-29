<div @if($pollingMs > 0) wire:poll.{{ $pollingMs }}ms @endif>
    @include('slipstream::livewire.partials.flash')

    <div class="ss-stats-row">
        <div class="ss-stat-card">
            <div class="ss-stat-card-title">Deployments</div>
            <div class="ss-stat-metrics">
                <div class="ss-stat-metric">
                    <div class="ss-stat-label">Today</div>
                    <div class="ss-stat-value">{{ $today }}</div>
                </div>
                <div class="ss-stat-metric">
                    <div class="ss-stat-label">This Week</div>
                    <div class="ss-stat-value">{{ $thisWeek }}</div>
                </div>
                <div class="ss-stat-metric">
                    <div class="ss-stat-label">Last Duration</div>
                    <div class="ss-stat-value">{{ $lastDurationSeconds !== null ? $lastDurationSeconds.' s' : '—' }}</div>
                </div>
            </div>
        </div>

        <div class="ss-stat-card">
            <div class="ss-stat-card-title">Fleet Health</div>
            <div class="ss-health-list">
                @forelse($fleet['servers'] as $server)
                    @php
                        $isOffline = $fleet['offline']->contains('id', $server->id);
                        $isDrift = $fleet['stale']->contains('id', $server->id);
                        $isPaused = $server->paused || ! $server->enabled;
                        $ok = ! $isOffline && ! $isDrift && ! $isPaused;
                    @endphp
                    <div class="ss-health-node" title="{{ $server->server_name }}{{ $server->current_sha ? ' · '.$drift->short($server->current_sha) : '' }}">
                        <div class="ss-health-label">{{ $server->server_name }}</div>
                        @if($ok)
                            <div class="ss-health-dot is-ok" aria-label="healthy">
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            </div>
                        @elseif($isOffline)
                            <div class="ss-health-dot is-err" aria-label="offline">
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            </div>
                        @else
                            <div class="ss-health-dot is-warn" aria-label="attention">
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="ss-health-empty ss-muted ss-small">No servers registered yet</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="ss-header">
        <div>
            <h1 class="ss-section-title" style="margin-bottom:0">Recent Deployments</h1>
            <p class="ss-muted ss-small">One row per server per push. Use fleet view to group by SHA.</p>
        </div>
        <div class="ss-actions">
            <button type="button" class="ss-btn ss-btn-danger" wire:click="rollback"
                    wire:confirm="Roll back ALL servers to their previous release? Code only — this cannot un-migrate."
                    wire:loading.attr="disabled">
                Rollback fleet
            </button>
        </div>
    </div>

    @if($driftSummary['has_drift'])
        <div class="ss-alert ss-alert-err">
            Fleet drift: some servers are not on
            <code>{{ $drift->short($driftSummary['canonical_sha']) }}</code>.
            See <a href="{{ route('slipstream.servers') }}">Servers</a>.
        </div>
    @endif

    <div class="ss-toolbar ss-toolbar-split">
        <select wire:model.live="statusFilter" class="ss-input ss-input-auto">
            <option value="">All statuses</option>
            <option value="queued">queued</option>
            <option value="pending">pending</option>
            <option value="building">building</option>
            <option value="deployed">deployed</option>
            <option value="failed">failed</option>
            <option value="rolled-back">rolled-back</option>
            <option value="superseded">superseded</option>
        </select>
        <div class="ss-viewtoggle" role="group" aria-label="View mode">
            <button type="button" wire:click="$set('viewMode', 'list')" @class(['is-active' => $viewMode === 'list']) title="Table" aria-label="Table view" aria-pressed="{{ $viewMode === 'list' ? 'true' : 'false' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <button type="button" wire:click="$set('viewMode', 'fleet')" @class(['is-active' => $viewMode === 'fleet']) title="Fleet cards" aria-label="Fleet card view" aria-pressed="{{ $viewMode === 'fleet' ? 'true' : 'false' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/></svg>
            </button>
        </div>
    </div>

    @if($viewMode === 'fleet')
        <div class="ss-fleet-list">
            @forelse($fleetGroups as $shaKey => $group)
                @php
                    /** @var \Illuminate\Support\Collection<int, \Slipstream\Models\Deployment> $group */
                    $head = $group->first();
                    $sha = $head->sha;
                @endphp
                <div class="ss-card ss-fleet-card">
                    <div class="ss-fleet-card-head">
                        <div>
                            <code class="ss-sha">{{ $sha ? substr($sha, 0, 7) : '—' }}</code>
                            @if($head->branch)
                                <span class="ss-muted">{{ $head->branch }}</span>
                            @endif
                            @if($head->commit_message)
                                <div class="ss-commit">{{ \Illuminate\Support\Str::limit($head->commit_message, 100) }}</div>
                            @endif
                        </div>
                        <div class="ss-fleet-card-meta">
                            <span class="ss-muted ss-small">{{ $head->created_at?->diffForHumans() }} · {{ $head->trigger }}</span>
                            @if($sha)
                                <button type="button" class="ss-btn ss-btn-sm" wire:click="redeploy({{ $head->id }})"
                                        wire:confirm="Redeploy {{ substr($sha, 0, 7) }} as a fresh release on ALL servers?"
                                        wire:loading.attr="disabled">
                                    Redeploy
                                </button>
                            @endif
                        </div>
                    </div>
                    <div class="ss-server-pills">
                        @foreach($group as $d)
                            <a href="{{ route('slipstream.deployment', $d) }}" class="ss-server-pill ss-pill-{{ $d->status }}" title="#{{ $d->id }}">
                                <span class="ss-pill-server">{{ $d->server_name }}</span>
                                @include('slipstream::livewire.partials.status', ['status' => $d->status])
                                @if($d->duration_ms)
                                    <span class="ss-muted ss-small">{{ number_format($d->duration_ms / 1000, 0) }}s</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="ss-card ss-muted">No deployments yet. Push to main or click Deploy.</div>
            @endforelse
        </div>
        <div class="ss-pagination">{{ $deployments->links() }}</div>
    @else
        <div class="ss-card ss-card-flush">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th>Started</th>
                        <th>Committer</th>
                        <th>Commit</th>
                        <th>Server</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deployments as $d)
                        <tr wire:key="dep-{{ $d->id }}">
                            <td class="ss-cell-num">{{ $d->started_at?->format('Y-m-d g:i:s A') ?? $d->created_at?->format('Y-m-d g:i:s A') }}</td>
                            <td>{{ $d->commit_author ?: '—' }}</td>
                            <td>
                                <a href="{{ route('slipstream.deployment', $d) }}" class="ss-commit-link">{{ $d->shortSha() ?: '—' }}</a>
                                @if($d->branch)<span class="ss-muted ss-small">({{ $d->branch }})</span>@endif
                                @if($d->commit_message)
                                    <div class="ss-muted ss-small">{{ \Illuminate\Support\Str::limit($d->commit_message, 60) }}</div>
                                @endif
                            </td>
                            <td>{{ $d->server_name }}</td>
                            <td>@include('slipstream::livewire.partials.status', ['status' => $d->status])</td>
                            <td class="ss-cell-num">
                                @if($d->duration_ms)
                                    {{ number_format($d->duration_ms / 1000, 0) }} {{ $d->duration_ms >= 2000 ? 'Seconds' : 'Second' }}
                                @else
                                    <span class="ss-dash">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="ss-row-actions">
                                    @if($d->sha)
                                        <button type="button" class="ss-btn ss-btn-sm" wire:click="redeploy({{ $d->id }})"
                                                wire:confirm="Redeploy {{ $d->shortSha() }} as a fresh release on ALL servers?">Redeploy</button>
                                    @endif
                                    <a href="{{ route('slipstream.deployment', $d) }}" class="ss-open" title="Open deployment #{{ $d->id }}">
                                        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="ss-muted">No deployments yet. Push to <code>main</code> or click <strong>Deploy</strong>.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="ss-pagination" style="padding: 0.85rem 1rem">{{ $deployments->links() }}</div>
        </div>
    @endif
</div>
