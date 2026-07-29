<?php

namespace Slipstream\Livewire;

use Illuminate\Support\Facades\Schema;
use Slipstream\Fleet\DeployQueue;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentHook;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;
use Slipstream\Support\GitRef;

/**
 * Secondary app bar: project name, branch/repo meta, Settings + Deploy modal.
 * Sits under the main nav; summary stats live on the Deployments page only.
 */
class ProjectChrome extends SlipstreamComponent
{
    public bool $showDeployModal = false;

    /** @var 'default'|'branch'|'tag' */
    public string $deployFrom = 'default';

    /** @var 'fleet'|'this' */
    public string $deployScope = 'fleet';

    /** @var list<string> Eligible fleet servers, display only — queue() re-resolves */
    public array $fleetTargets = [];

    public string $customBranch = '';

    public string $customTag = '';

    /** @var list<int|string> Hook IDs checked for this deploy */
    public array $selectedHookIds = [];

    public function openDeployModal(SettingsRepository $settings, DeployQueue $queue): void
    {
        $this->deployFrom = 'default';
        $this->deployScope = 'fleet';
        $this->customBranch = '';
        $this->customTag = '';
        $queue->ensureSelfRegistered();
        $this->fleetTargets = $queue->targets()->pluck('server_name')->all();
        $this->selectedHookIds = DeploymentHook::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->showDeployModal = true;
    }

    public function closeDeployModal(): void
    {
        $this->showDeployModal = false;
        $this->resetValidation();
    }

    public function confirmDeploy(SettingsRepository $settings, DeployQueue $queue): void
    {
        // Trim before validating so a pasted ref with stray whitespace is fixed
        // rather than rejected by the ref-shape rule.
        $this->customBranch = trim($this->customBranch);
        $this->customTag = trim($this->customTag);

        $rules = [
            'deployFrom' => 'required|in:default,branch,tag',
            'deployScope' => 'required|in:fleet,this',
        ];

        // A typed ref ends up as an argv element in `git fetch` — hold it to a
        // real branch/tag shape rather than any 255 characters.
        if ($this->deployFrom === 'branch') {
            $rules['customBranch'] = ['required', 'string', 'max:255', GitRef::rule()];
        }
        if ($this->deployFrom === 'tag') {
            $rules['customTag'] = ['required', 'string', 'max:255', GitRef::rule()];
        }

        $this->validate($rules);

        $defaultBranch = $settings->branch();
        $branch = match ($this->deployFrom) {
            'branch' => trim($this->customBranch),
            'tag' => trim($this->customTag),
            default => $defaultBranch,
        };

        $enabledHookIds = DeploymentHook::query()
            ->where('enabled', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $selected = array_map('intval', $this->selectedHookIds);
        $skipHookIds = array_values(array_diff($enabledHookIds, $selected));

        // Fleet scope resolves the eligible servers inside queue(); this-server
        // passes an explicit single target through the same path.
        $result = $queue->queue([
            'branch' => $branch,
            'trigger' => Deployment::TRIGGER_MANUAL,
            'initiator_user_id' => auth()->id(),
            'meta' => [
                'deploy_from' => $this->deployFrom,
                'skip_hook_ids' => $skipHookIds,
            ],
        ], $this->deployScope === 'this' ? [Slipstream::serverName()] : null);

        Activity::log('deploy.started', [
            'deployment' => $result['local']?->id,
            'batch' => $result['batch'],
            'scope' => $this->deployScope,
            'queued_for' => $result['queued'],
            'deploy_from' => $this->deployFrom,
            'branch' => $branch,
            'skip_hook_ids' => $skipHookIds,
        ]);

        $this->showDeployModal = false;
        $this->flashFleetResult('Deploy', $result);
    }

    public function render(SettingsRepository $settings)
    {
        $repoUrl = $settings->repoUrl();
        $repoLabel = $repoUrl
            ? preg_replace('#^(git@|https?://)([^/:]+)[:/]#', '$2/', (string) $repoUrl)
            : null;
        $repoLabel = is_string($repoLabel) ? rtrim(preg_replace('#\.git$#', '', $repoLabel) ?? $repoLabel, '/') : null;

        $hooks = Schema::hasTable('slipstream_deployment_hooks')
            ? DeploymentHook::query()
                ->where('enabled', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'step', 'position'])
            : collect();

        return view('slipstream::livewire.project-chrome', [
            'appName' => config('app.name', 'Laravel'),
            'branch' => $settings->branch(),
            'repoUrl' => $repoUrl,
            'repoLabel' => $repoLabel,
            'siteUrl' => config('app.url'),
            'serverName' => Slipstream::serverName(),
            'settingsEnabled' => Slipstream::viewEnabled('settings'),
            'deploymentsEnabled' => Slipstream::viewEnabled('deployments'),
            'hooks' => $hooks,
        ]);
    }
}
