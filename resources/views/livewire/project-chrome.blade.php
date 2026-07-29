<div wire:key="project-chrome">
    <div class="ss-subnav">
        <div class="ss-subnav-inner">
            @include('slipstream::livewire.partials.flash')

            <div class="ss-subnav-row">
                <div class="ss-subnav-identity">
                    <h1 class="ss-subnav-name">{{ $appName }}</h1>
                    <div class="ss-subnav-meta">
                        @if($repoLabel)
                            <span class="ss-meta-item" title="{{ $repoUrl }}">
                                <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                                {{ $repoLabel }}
                            </span>
                        @endif
                        <span class="ss-meta-item" title="Deploy branch">
                            <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M11.75 2.5a.75.75 0 100 1.5.75.75 0 000-1.5zm-2.25.75a2.25 2.25 0 113 2.122V6A2.5 2.5 0 0110 8.5H6a1 1 0 00-1 1v1.128a2.251 2.251 0 11-1.5 0V5.372a2.25 2.25 0 111.5 0v1.836A2.492 2.492 0 016 7h4a1 1 0 001-1v-.628A2.25 2.25 0 019.5 3.25zM4.25 12a.75.75 0 100 1.5.75.75 0 000-1.5zM3.5 3.25a.75.75 0 111.5 0 .75.75 0 01-1.5 0z"/></svg>
                            {{ $branch }}
                        </span>
                        @if($siteUrl)
                            <span class="ss-meta-item" title="App URL">
                                <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.775 3.275a.75.75 0 001.06 1.06l1.25-1.25a2 2 0 112.83 2.83l-2.5 2.5a2 2 0 01-2.83 0 .75.75 0 00-1.06 1.06 3.5 3.5 0 004.95 0l2.5-2.5a3.5 3.5 0 00-4.95-4.95l-1.25 1.25zm-4.69 9.64a2 2 0 010-2.83l2.5-2.5a2 2 0 012.83 0 .75.75 0 001.06-1.06 3.5 3.5 0 00-4.95 0l-2.5 2.5a3.5 3.5 0 004.95 4.95l1.25-1.25a.75.75 0 00-1.06-1.06l-1.25 1.25a2 2 0 01-2.83 0z"/></svg>
                                <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer">{{ parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl }}</a>
                            </span>
                        @endif
                        <span class="ss-meta-item ss-meta-muted" title="This node">
                            <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M1.75 2.5h12.5a.75.75 0 010 1.5H1.75a.75.75 0 010-1.5zM1.75 7h12.5a.75.75 0 010 1.5H1.75a.75.75 0 010-1.5zM1.75 11.5h12.5a.75.75 0 010 1.5H1.75a.75.75 0 010-1.5z"/></svg>
                            {{ $serverName }}
                        </span>
                    </div>
                </div>
                <div class="ss-subnav-actions">
                    @if($settingsEnabled)
                        <a href="{{ route('slipstream.settings') }}" class="ss-btn ss-btn-ghost">
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
                            Settings
                        </a>
                    @endif
                    @if($deploymentsEnabled)
                        <button type="button" class="ss-btn ss-btn-primary ss-btn-deploy" wire:click="openDeployModal">
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.5 16a3.5 3.5 0 01-.369-6.98 4 4 0 117.753-1.977A4.5 4.5 0 1113.5 16h-8z"/></svg>
                            Deploy
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($showDeployModal)
        <div class="ss-modal-overlay" wire:key="deploy-modal" wire:click.self="closeDeployModal">
            <div class="ss-modal ss-modal-deploy" role="dialog" aria-modal="true" aria-labelledby="ss-deploy-title">
                <div class="ss-modal-head">
                    <h2 id="ss-deploy-title">
                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.5 16a3.5 3.5 0 01-.369-6.98 4 4 0 117.753-1.977A4.5 4.5 0 1113.5 16h-8z"/></svg>
                        Deploy {{ $appName }}
                    </h2>
                    <button type="button" class="ss-modal-close" wire:click="closeDeployModal" aria-label="Close">&times;</button>
                </div>
                <form wire:submit="confirmDeploy">
                    <div class="ss-modal-body">
                        <div class="ss-field">
                            <label class="ss-fieldset-label">Deploy From</label>
                            <div class="ss-radio-list">
                                <label class="ss-radio">
                                    <input type="radio" wire:model.live="deployFrom" value="default">
                                    <span>Default Branch ({{ $branch }})</span>
                                </label>
                                <label class="ss-radio">
                                    <input type="radio" wire:model.live="deployFrom" value="branch">
                                    <span>A Different Branch</span>
                                </label>
                                @if($deployFrom === 'branch')
                                    <div class="ss-radio-extra">
                                        <input type="text" class="ss-input" wire:model="customBranch" placeholder="feature/my-branch" autocomplete="off">
                                        @error('customBranch') <span class="ss-err">{{ $message }}</span> @enderror
                                    </div>
                                @endif
                                <label class="ss-radio">
                                    <input type="radio" wire:model.live="deployFrom" value="tag">
                                    <span>Tag</span>
                                </label>
                                @if($deployFrom === 'tag')
                                    <div class="ss-radio-extra">
                                        <input type="text" class="ss-input" wire:model="customTag" placeholder="v1.2.3" autocomplete="off">
                                        @error('customTag') <span class="ss-err">{{ $message }}</span> @enderror
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if(count($fleetTargets) > 1)
                            <div class="ss-field">
                                <label class="ss-fieldset-label">Deploy To</label>
                                <div class="ss-radio-list">
                                    <label class="ss-radio">
                                        <input type="radio" wire:model.live="deployScope" value="fleet">
                                        <span>All servers ({{ count($fleetTargets) }})</span>
                                    </label>
                                    <label class="ss-radio">
                                        <input type="radio" wire:model.live="deployScope" value="this">
                                        <span>This server only ({{ $serverName }})</span>
                                    </label>
                                </div>
                                @if($deployScope === 'fleet')
                                    <p class="ss-muted ss-small" style="margin-top:0.55rem">
                                        This server deploys immediately; {{ implode(', ', array_values(array_diff($fleetTargets, [$serverName]))) }} pick the deploy up within seconds (nudge) or at worst on their next minute heartbeat.
                                    </p>
                                @endif
                            </div>
                        @endif

                        @if($hooks->isNotEmpty())
                            <div class="ss-field" style="margin-bottom:0">
                                <label class="ss-fieldset-label">Deploy Hooks</label>
                                <div class="ss-check-list">
                                    @foreach($hooks as $hook)
                                        <label class="ss-check">
                                            <input type="checkbox" value="{{ $hook->id }}" wire:model="selectedHookIds">
                                            <span>{{ $hook->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="ss-muted ss-small" style="margin-top:0.55rem">
                                    Unchecked hooks are skipped for this deploy only. Built-in steps always run.
                                </p>
                            </div>
                        @endif
                    </div>
                    <div class="ss-modal-foot">
                        <button type="submit" class="ss-btn ss-btn-primary ss-btn-deploy" wire:loading.attr="disabled">
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.5 16a3.5 3.5 0 01-.369-6.98 4 4 0 117.753-1.977A4.5 4.5 0 1113.5 16h-8z"/></svg>
                            <span wire:loading.remove wire:target="confirmDeploy">Deploy</span>
                            <span wire:loading wire:target="confirmDeploy">Deploying…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
