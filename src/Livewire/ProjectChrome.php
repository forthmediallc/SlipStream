<?php

namespace Slipstream\Livewire;

use Illuminate\Support\Facades\Schema;
use Slipstream\Deploy\ProcessDetacher;
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

    public string $customBranch = '';

    public string $customTag = '';

    /** @var list<int|string> Hook IDs checked for this deploy */
    public array $selectedHookIds = [];

    public function openDeployModal(SettingsRepository $settings): void
    {
        $this->deployFrom = 'default';
        $this->customBranch = '';
        $this->customTag = '';
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

    public function confirmDeploy(SettingsRepository $settings, ProcessDetacher $detacher): void
    {
        // Trim before validating so a pasted ref with stray whitespace is fixed
        // rather than rejected by the ref-shape rule.
        $this->customBranch = trim($this->customBranch);
        $this->customTag = trim($this->customTag);

        $rules = [
            'deployFrom' => 'required|in:default,branch,tag',
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

        $deployment = Deployment::query()->create([
            'server_name' => Slipstream::serverName(),
            'branch' => $branch,
            'trigger' => Deployment::TRIGGER_MANUAL,
            'status' => Deployment::STATUS_PENDING,
            'initiator_user_id' => auth()->id(),
            'meta' => [
                'deploy_from' => $this->deployFrom,
                'skip_hook_ids' => $skipHookIds,
            ],
        ]);

        try {
            $detacher->spawnArtisan([
                'slipstream:deploy',
                '--deployment='.$deployment->id,
            ]);
            Activity::log('deploy.started', [
                'deployment' => $deployment->id,
                'deploy_from' => $this->deployFrom,
                'branch' => $branch,
                'skip_hook_ids' => $skipHookIds,
            ]);
            $this->showDeployModal = false;
            session()->flash('slipstream_status', "Deploy #{$deployment->id} started.");
        } catch (\Throwable $e) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            session()->flash('slipstream_error', 'Failed to start deploy: '.$e->getMessage());
        }
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
