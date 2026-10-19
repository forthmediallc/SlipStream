<?php

namespace Slipstream\Livewire;

use Slipstream\Release\DeployRef;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;
use Slipstream\Support\GitRef;
use Slipstream\Support\SharedPaths;

class SettingsPage extends SlipstreamComponent
{
    public string $repo_url = '';

    public string $branch = 'main';

    public int $keep_releases = 5;

    /**
     * Editable rows: [['in_release' => 'public/uploads', 'on_server' => 'shared/uploads'], …]
     *
     * @var list<array{in_release: string, on_server: string}>
     */
    public array $shared_paths = [];

    public bool $notify_on_success = true;

    public bool $notify_on_failure = true;

    public string $notification_mail = '';

    public string $notification_slack_url = '';

    public string $notification_discord_url = '';

    public string $notification_webhook_url = '';

    public function mount(SettingsRepository $settings): void
    {
        $this->repo_url = (string) ($settings->repoUrl() ?? '');
        $this->branch = $settings->branch();
        $this->keep_releases = $settings->keepReleases();
        $this->shared_paths = $this->pathsToRows($settings->sharedPaths());

        // What is in effect: an SLIPSTREAM_NOTIFY_* value wins over the stored one.
        $this->notify_on_success = filter_var($settings->notification('on_success'), FILTER_VALIDATE_BOOLEAN);
        $this->notify_on_failure = filter_var($settings->notification('on_failure'), FILTER_VALIDATE_BOOLEAN);
        $this->notification_mail = (string) ($settings->notification('mail') ?? '');
        $this->notification_slack_url = (string) ($settings->notification('slack_url') ?? '');
        $this->notification_discord_url = (string) ($settings->notification('discord_url') ?? '');
        $this->notification_webhook_url = (string) ($settings->notification('webhook_url') ?? '');
    }

    public function addSharedPath(): void
    {
        $this->shared_paths[] = ['in_release' => '', 'on_server' => ''];
    }

    public function removeSharedPath(int $index): void
    {
        if (! array_key_exists($index, $this->shared_paths)) {
            return;
        }

        unset($this->shared_paths[$index]);
        $this->shared_paths = array_values($this->shared_paths);
    }

    public function save(SettingsRepository $settings): void
    {
        $this->branch = trim($this->branch);

        $this->validate([
            'branch' => ['required', 'string', 'max:255', GitRef::rule()],
            'keep_releases' => 'required|integer|min:1|max:50',
            'repo_url' => 'nullable|string|max:500',
            'shared_paths' => 'array',
            'shared_paths.*.in_release' => 'nullable|string|max:500',
            'shared_paths.*.on_server' => 'nullable|string|max:500',
            'notification_mail' => 'nullable|email|max:255',
            'notification_slack_url' => 'nullable|url|max:500',
            'notification_discord_url' => 'nullable|url|max:500',
            'notification_webhook_url' => 'nullable|url|max:500',
        ]);

        $locked = $this->lockedFields();

        $paths = [];
        if (! isset($locked['shared_paths'])) {
            $paths = $this->rowsToPaths();
            if ($paths === null) {
                return;
            }
        }

        // A field the environment decides keeps its stored value: writing
        // what the form shows would only copy config into the database.
        $write = function (string $key, mixed $value, ?bool $encrypted = null) use ($settings, $locked): void {
            if (! isset($locked[$key])) {
                $settings->set($key, $value, $encrypted);
            }
        };

        $write('repo_url', $this->repo_url !== '' ? $this->repo_url : null);
        $write('branch', $this->branch);
        $write('keep_releases', (string) $this->keep_releases);
        $write('shared_paths', $paths);

        $write('notify_on_success', $this->notify_on_success ? '1' : '0');
        $write('notify_on_failure', $this->notify_on_failure ? '1' : '0');
        $write('notification_mail', $this->notification_mail !== '' ? $this->notification_mail : null);
        $write('notification_slack_url', $this->notification_slack_url !== '' ? $this->notification_slack_url : null, true);
        $write('notification_discord_url', $this->notification_discord_url !== '' ? $this->notification_discord_url : null, true);
        $write('notification_webhook_url', $this->notification_webhook_url !== '' ? $this->notification_webhook_url : null, true);

        // Keep the editor in sync with what we persisted (trimmed / empties dropped).
        if (! isset($locked['shared_paths'])) {
            $this->shared_paths = $this->pathsToRows($paths);
        }

        Activity::log('settings.saved');
        session()->flash('slipstream_status', 'Settings saved.');
    }

    public function render()
    {
        return view('slipstream::livewire.settings-page', [
            'serverName' => Slipstream::serverName(),
            'appRoot' => Slipstream::appRoot(),
            'pollMode' => Slipstream::pollMode(),
            'deployRef' => $this->deployRefLabel(),
            'locked' => $this->lockedFields(),
        ]);
    }

    /**
     * Fields something other than this form decides, shown read-only and
     * left alone by save(). In poll mode the deploy settings come from config
     * and the shared .env, so a new instance deploys like an old one; in any
     * mode an SLIPSTREAM_REPOSITORY or SLIPSTREAM_NOTIFY_* value wins over
     * the stored one.
     *
     * @return array<string, string> field => what sets it
     */
    protected function lockedFields(): array
    {
        $locked = [];

        if (Slipstream::pollMode()) {
            $locked['branch'] = $this->deployRefIsBranch() ? 'SLIPSTREAM_DEPLOY_REF' : 'SLIPSTREAM_BRANCH';
            $locked['keep_releases'] = 'SLIPSTREAM_KEEP_RELEASES';
            $locked['shared_paths'] = 'deploy.shared_paths in config/slipstream.php';
        }

        $repository = config('slipstream.trigger.repository');
        if (is_string($repository) && trim($repository) !== '') {
            $locked['repo_url'] = 'SLIPSTREAM_REPOSITORY';
        }

        foreach ([
            'notify_on_success' => 'on_success',
            'notify_on_failure' => 'on_failure',
            'notification_mail' => 'mail',
            'notification_slack_url' => 'slack_url',
            'notification_discord_url' => 'discord_url',
            'notification_webhook_url' => 'webhook_url',
        ] as $field => $name) {
            $value = config("slipstream.notifications.{$name}");
            if ($value !== null && $value !== '') {
                $locked[$field] = 'SLIPSTREAM_NOTIFY_'.strtoupper($name);
            }
        }

        return $locked;
    }

    protected function deployRefLabel(): ?string
    {
        if (! Slipstream::pollMode()) {
            return null;
        }

        try {
            return DeployRef::fromConfig()->describe();
        } catch (\Throwable) {
            return 'an invalid SLIPSTREAM_DEPLOY_REF';
        }
    }

    protected function deployRefIsBranch(): bool
    {
        try {
            return DeployRef::fromConfig()->kind === DeployRef::BRANCH;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $paths
     * @return list<array{in_release: string, on_server: string}>
     */
    protected function pathsToRows(array $paths): array
    {
        $rows = [];
        foreach ($paths as $inRelease => $onServer) {
            $rows[] = [
                'in_release' => (string) $inRelease,
                'on_server' => (string) $onServer,
            ];
        }

        return $rows;
    }

    /**
     * Build the map to persist, or null if validation errors were added.
     *
     * @return array<string, string>|null
     */
    protected function rowsToPaths(): ?array
    {
        $paths = [];

        foreach ($this->shared_paths as $i => $row) {
            $in = $this->normalizePath((string) ($row['in_release'] ?? ''));
            $on = $this->normalizePath((string) ($row['on_server'] ?? ''));

            if ($in === '' && $on === '') {
                continue;
            }

            if ($in === '' || $on === '') {
                $this->addError(
                    "shared_paths.{$i}.".($in === '' ? 'in_release' : 'on_server'),
                    'Both paths are required for each row (or leave both empty).'
                );

                return null;
            }

            if ($this->isUnsafePath($in) || $this->isUnsafePath($on)) {
                $this->addError(
                    "shared_paths.{$i}.in_release",
                    'Paths must be relative (no leading slash) and must not contain "..".'
                );

                return null;
            }

            if (isset($paths[$in])) {
                $this->addError(
                    "shared_paths.{$i}.in_release",
                    "Duplicate release path: {$in}"
                );

                return null;
            }

            $paths[$in] = $on;
        }

        return $paths;
    }

    protected function normalizePath(string $path): string
    {
        return SharedPaths::normalize($path);
    }

    protected function isUnsafePath(string $path): bool
    {
        return SharedPaths::isUnsafe($path);
    }
}
