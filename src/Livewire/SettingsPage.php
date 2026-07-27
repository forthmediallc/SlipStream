<?php

namespace Slipstream\Livewire;

use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;
use Slipstream\Support\GitRef;

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

        $this->notify_on_success = filter_var($settings->get('notify_on_success', true), FILTER_VALIDATE_BOOLEAN);
        $this->notify_on_failure = filter_var($settings->get('notify_on_failure', true), FILTER_VALIDATE_BOOLEAN);
        $this->notification_mail = (string) ($settings->get('notification_mail') ?? '');
        $this->notification_slack_url = (string) ($settings->get('notification_slack_url') ?? '');
        $this->notification_discord_url = (string) ($settings->get('notification_discord_url') ?? '');
        $this->notification_webhook_url = (string) ($settings->get('notification_webhook_url') ?? '');
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

        $paths = $this->rowsToPaths();
        if ($paths === null) {
            return;
        }

        $settings->set('repo_url', $this->repo_url !== '' ? $this->repo_url : null);
        $settings->set('branch', $this->branch);
        $settings->set('keep_releases', (string) $this->keep_releases);
        $settings->set('shared_paths', $paths);

        $settings->set('notify_on_success', $this->notify_on_success ? '1' : '0');
        $settings->set('notify_on_failure', $this->notify_on_failure ? '1' : '0');
        $settings->set('notification_mail', $this->notification_mail !== '' ? $this->notification_mail : null);
        $settings->set('notification_slack_url', $this->notification_slack_url !== '' ? $this->notification_slack_url : null, encrypted: true);
        $settings->set('notification_discord_url', $this->notification_discord_url !== '' ? $this->notification_discord_url : null, encrypted: true);
        $settings->set('notification_webhook_url', $this->notification_webhook_url !== '' ? $this->notification_webhook_url : null, encrypted: true);

        // Keep the editor in sync with what we persisted (trimmed / empties dropped).
        $this->shared_paths = $this->pathsToRows($paths);

        Activity::log('settings.saved');
        session()->flash('slipstream_status', 'Settings saved.');
    }

    public function render()
    {
        return view('slipstream::livewire.settings-page', [
            'serverName' => Slipstream::serverName(),
            'appRoot' => Slipstream::appRoot(),
        ]);
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
        $path = trim(str_replace('\\', '/', $path));
        $path = trim($path, '/');

        return $path;
    }

    protected function isUnsafePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return true;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return true;
            }
        }

        return false;
    }
}
