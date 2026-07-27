<div>
    @include('slipstream::livewire.partials.flash')
    <div class="ss-header">
        <div>
            <h1 class="ss-section-title" style="margin-bottom:0">Webhook</h1>
            <p class="ss-muted ss-small">
                Push → this server (<code>{{ $serverName }}</code>).
                Providers: <strong>GitHub</strong>, <strong>GitLab</strong>, <strong>Bitbucket</strong> (auto-detected).
            </p>
        </div>
    </div>

    <div class="ss-grid-2">
        <div class="ss-card">
            <h2>Endpoint</h2>
            <p class="ss-muted">Same URL for all providers. Content-Type: application/json.</p>
            <div class="ss-code-block">{{ $endpoint }}</div>
            <div class="ss-provider-notes">
                <p><strong>GitHub</strong> — push events; secret = HMAC (X-Hub-Signature-256).</p>
                <p><strong>GitLab</strong> — Push Hook; secret = token (X-Gitlab-Token).</p>
                <p><strong>Bitbucket</strong> — repo:push; secret = HMAC (X-Hub-Signature).</p>
            </div>
        </div>
        <div class="ss-card">
            <h2>Shared secret</h2>
            <p class="ss-muted">Encrypted in DB. Use the same value in the provider and for relay HMAC.</p>
            @if($secret)
                <div class="ss-code-block">{{ $secret }}</div>
            @else
                <div class="ss-code-block ss-muted">••••••••••••••••••••••••••••••••</div>
            @endif
            <div class="ss-actions" style="margin-top:1rem">
                <button type="button" class="ss-btn" wire:click="reveal">Reveal</button>
                <button type="button" class="ss-btn ss-btn-danger" wire:click="rotate"
                        wire:confirm="Rotate the webhook secret? Update every git host immediately.">
                    Rotate
                </button>
                <button type="button" class="ss-btn" wire:click="sendTestPing" wire:loading.attr="disabled">
                    Send test ping
                </button>
            </div>
            <p class="ss-muted ss-small" style="margin-top:.5rem">
                Test ping signs a payload with this secret and runs it through the real
                verification path — proves the endpoint is wired without leaving the dashboard.
            </p>
        </div>
        <div class="ss-card">
            <h2>Fleet key</h2>
            <p class="ss-muted">
                Signs relay fan-out and env-sync nudges between your own servers. Encrypted in
                the DB and read from there by every node — it is never revealed and never leaves
                the fleet.
            </p>
            @if($peerSeparated)
                <div class="ss-code-block ss-muted">separate key in use ••••••••••••••••</div>
                <p class="ss-muted ss-small" style="margin-top:.5rem">
                    Independent of the webhook secret above, so a secret leaked at your git host
                    cannot forge a deploy on any node.
                </p>
            @else
                <div class="ss-code-block ss-muted">using the webhook secret (legacy)</div>
                <p class="ss-muted ss-small" style="margin-top:.5rem">
                    Peer traffic is currently signed with the same secret you gave GitHub /
                    GitLab / Bitbucket. Anyone who obtains it there could forge a relay push and
                    deploy any commit in your mirror. Generate a separate key once every node
                    runs this version.
                </p>
            @endif
            <div class="ss-actions" style="margin-top:1rem">
                <button type="button" class="ss-btn{{ $peerSeparated ? ' ss-btn-danger' : '' }}"
                        wire:click="rotatePeerSecret"
                        wire:confirm="{{ $peerSeparated
                            ? 'Rotate the fleet key? Peers pick it up from the shared database immediately.'
                            : 'Generate a separate fleet key? Every node must already run this Slipstream version.' }}">
                    {{ $peerSeparated ? 'Rotate fleet key' : 'Generate separate fleet key' }}
                </button>
            </div>
        </div>
    </div>

    <form wire:submit="saveRelay" class="ss-card" style="margin-top:0.25rem">
        <h2>Relay mode</h2>
        <p class="ss-muted">
            When enabled, this node fans out a signed POST to each peer’s
            <code>{{ $relayEndpoint }}</code> after accepting an external push.
            Peers need a <code>base_url</code> on the Servers page.
        </p>
        <div class="ss-field">
            <label class="ss-check">
                <input type="checkbox" wire:model="relay_enabled">
                Enable webhook relay (single provider webhook → fleet)
            </label>
        </div>
        <div class="ss-field">
            <label>Peer readiness</label>
            <ul class="ss-muted ss-peer-list">
                @forelse($peers as $peer)
                    <li>
                        <code>{{ $peer->server_name }}</code>
                        @if($peer->base_url)
                            → <code>{{ $peer->base_url }}</code>
                            <span class="ss-badge ss-badge-deployed">ready</span>
                        @else
                            <span class="ss-badge ss-badge-failed">missing base_url</span>
                        @endif
                    </li>
                @empty
                    <li>No other servers registered yet.</li>
                @endforelse
            </ul>
        </div>
        <button type="submit" class="ss-btn ss-btn-primary">Save relay setting</button>
    </form>

    <h2 class="ss-section-title" style="margin-top:0.5rem">Delivery log</h2>
    <div class="ss-card ss-card-flush">
        <table class="ss-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Provider</th>
                    <th>Event</th>
                    <th>Ref</th>
                    <th>SHA</th>
                    <th>Sig</th>
                    <th>Match</th>
                    <th>Outcome</th>
                    <th>Deploy</th>
                    <th>When</th>
                </tr>
            </thead>
            <tbody>
                @forelse($deliveries as $d)
                    <tr>
                        <td class="ss-cell-num">{{ $d->id }}</td>
                        <td><span class="ss-badge">{{ $d->provider }}</span></td>
                        <td>{{ $d->event }}</td>
                        <td><code class="ss-small">{{ $d->ref ?: '—' }}</code></td>
                        <td><code class="ss-commit-link" style="cursor:default">{{ $d->sha ? substr($d->sha, 0, 7) : '—' }}</code></td>
                        <td>{{ $d->signature_valid ? '✓' : '✗' }}</td>
                        <td>{{ $d->matched_pipeline ? '✓' : '—' }}</td>
                        <td><span class="ss-badge">{{ $d->outcome }}</span></td>
                        <td>
                            @if($d->deployment_id)
                                <a href="{{ route('slipstream.deployment', $d->deployment_id) }}">#{{ $d->deployment_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="ss-muted">{{ $d->created_at?->diffForHumans() }}</td>
                    </tr>
                    @if($d->outcome_detail)
                        <tr class="ss-subrow">
                            <td colspan="10" class="ss-muted">{{ $d->outcome_detail }}</td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="10" class="ss-muted">No deliveries yet. Send a test ping from your git host.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="ss-pagination" style="padding:0.85rem 1rem">{{ $deliveries->links() }}</div>
    </div>
</div>
