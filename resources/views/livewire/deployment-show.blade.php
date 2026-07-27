<div @if($pollingMs > 0) wire:poll.{{ $pollingMs }}ms @endif>
    @include('slipstream::livewire.partials.flash')
    <div class="ss-header">
        <div>
            <p class="ss-muted ss-small" style="margin-bottom:0.4rem">
                <a href="{{ route('slipstream.deployments') }}">← Deployments</a>
            </p>
            <h1 class="ss-section-title" style="margin-bottom:0.35rem">Deployment #{{ $deployment->id }}</h1>
            <p class="ss-muted" style="margin:0; display:flex; flex-wrap:wrap; gap:0.5rem 0.85rem; align-items:center">
                @include('slipstream::livewire.partials.status', ['status' => $deployment->status])
                @if($deployment->shortSha())
                    <code>{{ $deployment->shortSha() }}</code>
                @endif
                <span>{{ $deployment->server_name }}</span>
                <span>{{ $deployment->trigger }}</span>
                @if($deployment->duration_ms)
                    <span>{{ number_format($deployment->duration_ms / 1000, 0) }}s</span>
                @endif
            </p>
            @if($deployment->commit_message)
                <p style="margin:0.65rem 0 0">
                    {{ $deployment->commit_message }}
                    @if($deployment->commit_author)
                        <span class="ss-muted">— {{ $deployment->commit_author }}</span>
                    @endif
                </p>
            @endif
            @if($deployment->failure_reason)
                <div class="ss-alert ss-alert-err" style="margin-top:0.85rem">{{ $deployment->failure_reason }}</div>
            @endif
            @if($deployment->warnings)
                <div class="ss-alert ss-alert-warn" style="margin-top:0.85rem">
                    <strong>Deployed with warnings:</strong>
                    @foreach(explode("\n", $deployment->warnings) as $warning)
                        <div>{{ $warning }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="ss-card ss-log ss-card-flush">
        <div class="ss-log-header">Live log</div>
        @if($truncated > 0)
            <div class="ss-muted ss-small" style="padding:0.5rem 1rem; border-bottom:1px solid var(--ss-border)">
                Showing the most recent {{ $events->count() }} lines ({{ number_format($truncated) }} earlier lines hidden).
            </div>
        @endif
        <pre class="ss-log-body">@foreach($events as $event)<span class="ss-log-line ss-log-{{ $event->level }}">[{{ $event->created_at?->format('H:i:s') }}]@if($event->step) [{{ $event->step }}]@endif {{ $event->message }}</span>
@endforeach@if($events->isEmpty())<span class="ss-muted">Waiting for log output…</span>@endif</pre>
    </div>
</div>
