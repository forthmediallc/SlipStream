<div>
    @include('slipstream::livewire.partials.flash')
    <div class="ss-header">
        <div>
            <h1 class="ss-section-title" style="margin-bottom:0">Settings</h1>
            <p class="ss-muted ss-small">Fleet deploy defaults. Server: <code>{{ $serverName }}</code> · App root: <code>{{ $appRoot }}</code></p>
        </div>
    </div>

    <form wire:submit="save" class="ss-card">
        <h2>Repository</h2>
        <div class="ss-field">
            <label>Repo URL</label>
            <input type="text" class="ss-input" wire:model="repo_url" placeholder="git@github.com:org/app.git">
            @error('repo_url') <span class="ss-err">{{ $message }}</span> @enderror
        </div>
        <div class="ss-grid-2">
            <div class="ss-field">
                <label>Branch</label>
                <input type="text" class="ss-input" wire:model="branch">
                @error('branch') <span class="ss-err">{{ $message }}</span> @enderror
            </div>
            <div class="ss-field">
                <label>Keep releases</label>
                <input type="number" class="ss-input" wire:model="keep_releases" min="1" max="50">
                @error('keep_releases') <span class="ss-err">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="ss-shared-paths">
            <div class="ss-shared-paths-head">
                <div class="ss-shared-paths-intro">
                    <h2>Shared paths</h2>
                    <p class="ss-muted ss-small">
                        Folders that must survive deploys. Each release gets a symlink into a durable folder under the app root.
                        Leave empty if <code>.env</code> and <code>storage/</code> are enough (most apps).
                    </p>
                </div>
                <button type="button" class="ss-btn ss-btn-sm" wire:click="addSharedPath">
                    + Add path
                </button>
            </div>

            <div class="ss-shared-always">
                <span class="ss-shared-always-label">Always shared</span>
                <code>.env</code>
                <code>storage/</code>
                <code>auth.json</code>
                <span class="ss-muted ss-small">Handled automatically — no need to list them</span>
            </div>

            @if (count($shared_paths) === 0)
                <div class="ss-shared-empty">
                    <p class="ss-muted">No extra shared paths yet.</p>
                    <p class="ss-muted ss-small">
                        Click <strong>Add path</strong> only if you have folders outside <code>storage/</code>
                        that must persist (uploads, generated files, etc.).
                    </p>
                </div>
            @else
                <div class="ss-shared-rows">
                    @foreach ($shared_paths as $i => $row)
                        <div class="ss-shared-row" wire:key="shared-path-{{ $i }}">
                            <div class="ss-shared-field">
                                <label for="shared-in-{{ $i }}">In each release</label>
                                <span class="ss-shared-hint">What the app expects</span>
                                <input
                                    id="shared-in-{{ $i }}"
                                    type="text"
                                    class="ss-input ss-mono"
                                    wire:model="shared_paths.{{ $i }}.in_release"
                                    placeholder="public/uploads"
                                    autocomplete="off"
                                    spellcheck="false"
                                >
                                @error("shared_paths.{$i}.in_release") <span class="ss-err">{{ $message }}</span> @enderror
                            </div>
                            <div class="ss-shared-arrow" title="is stored at" aria-hidden="true">→</div>
                            <div class="ss-shared-field">
                                <label for="shared-on-{{ $i }}">Store on server</label>
                                <span class="ss-shared-hint">Under app root</span>
                                <input
                                    id="shared-on-{{ $i }}"
                                    type="text"
                                    class="ss-input ss-mono"
                                    wire:model="shared_paths.{{ $i }}.on_server"
                                    placeholder="shared/uploads"
                                    autocomplete="off"
                                    spellcheck="false"
                                >
                                @error("shared_paths.{$i}.on_server") <span class="ss-err">{{ $message }}</span> @enderror
                            </div>
                            <div class="ss-shared-row-actions">
                                <button
                                    type="button"
                                    class="ss-btn ss-btn-sm ss-btn-danger"
                                    wire:click="removeSharedPath({{ $i }})"
                                    title="Remove this path"
                                    aria-label="Remove shared path"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="ss-shared-foot ss-muted ss-small">
                    Example: <code>public/uploads</code> → <code>shared/uploads</code>.
                    Relative paths only (no leading <code>/</code>, no <code>..</code>).
                </p>
            @endif
            @error('shared_paths') <span class="ss-err">{{ $message }}</span> @enderror
        </div>

        <div class="ss-form-actions">
            <button type="submit" class="ss-btn ss-btn-primary">Save settings</button>
        </div>
    </form>

    <form wire:submit="save" class="ss-card" style="margin-top:0.25rem">
        <h2>Notifications</h2>
        <p class="ss-muted">Fired when a deploy or rollback finishes on this fleet (shared settings).</p>
        <div class="ss-field">
            <label class="ss-check"><input type="checkbox" wire:model="notify_on_success"> Notify on success</label>
        </div>
        <div class="ss-field">
            <label class="ss-check"><input type="checkbox" wire:model="notify_on_failure"> Notify on failure</label>
        </div>
        <div class="ss-field">
            <label>Mail to</label>
            <input type="email" class="ss-input" wire:model="notification_mail" placeholder="ops@example.com">
            @error('notification_mail') <span class="ss-err">{{ $message }}</span> @enderror
        </div>
        <div class="ss-field">
            <label>Slack incoming webhook URL</label>
            <input type="url" class="ss-input" wire:model="notification_slack_url" placeholder="https://hooks.slack.com/…">
            @error('notification_slack_url') <span class="ss-err">{{ $message }}</span> @enderror
        </div>
        <div class="ss-field">
            <label>Discord webhook URL</label>
            <input type="url" class="ss-input" wire:model="notification_discord_url" placeholder="https://discord.com/api/webhooks/…">
            @error('notification_discord_url') <span class="ss-err">{{ $message }}</span> @enderror
        </div>
        <div class="ss-field">
            <label>Generic webhook URL (JSON POST)</label>
            <input type="url" class="ss-input" wire:model="notification_webhook_url" placeholder="https://…">
            @error('notification_webhook_url') <span class="ss-err">{{ $message }}</span> @enderror
        </div>
        <button type="submit" class="ss-btn ss-btn-primary">Save notifications</button>
    </form>

    <p class="ss-muted ss-small">Looking for the deploy pipeline and hooks? They live under <a href="{{ route('slipstream.hooks') }}">Deployment Hooks</a>.</p>
</div>
