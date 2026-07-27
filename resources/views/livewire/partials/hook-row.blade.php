<tr @class(['ss-hook-row', 'ss-row-offline' => ! $hook->enabled]) wire:key="hook-{{ $hook->id }}">
    <td>
        <span class="ss-reorder">
            <button type="button" class="ss-btn ss-btn-sm" wire:click="moveHook({{ $hook->id }}, 'up')" title="Move up">▲</button>
            <button type="button" class="ss-btn ss-btn-sm" wire:click="moveHook({{ $hook->id }}, 'down')" title="Move down">▼</button>
        </span>
    </td>
    <td>
        <strong>{{ $hook->name }}</strong>
        @if($hook->fleet_lock)<span class="ss-tag ss-tag-lock" title="Runs on one server per commit">fleet lock</span>@endif
        @unless($hook->enabled)
            <span class="ss-badge">disabled</span>
        @endunless
        <div class="ss-muted ss-small">{{ $hook->position }} <code>{{ $hook->step }}</code></div>
    </td>
    <td>
        <span class="ss-run-as" title="Hooks run as the deploy user (typically the web/app user)">deploy</span>
    </td>
    <td class="ss-muted ss-small">
        {{ $hook->server_names === null || $hook->server_names === [] ? 'All servers' : implode(', ', $hook->server_names) }}
    </td>
    <td>
        <div class="ss-row-actions">
            <button type="button" class="ss-icon-btn" wire:click="editHook({{ $hook->id }})" title="Edit hook" aria-label="Edit hook">
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
            </button>
            <button type="button" class="ss-icon-btn ss-icon-btn-danger" wire:click="deleteHook({{ $hook->id }})" wire:confirm="Delete hook “{{ $hook->name }}”?" title="Delete hook" aria-label="Delete hook">
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
        </div>
    </td>
</tr>
