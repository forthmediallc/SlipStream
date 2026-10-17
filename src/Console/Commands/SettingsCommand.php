<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;
use Slipstream\Support\GitRef;
use Slipstream\Support\SharedPaths;

/**
 * The Settings page from the shell, plus the Webhook page's relay switch:
 * repository, default branch, how many releases to keep, shared paths, and
 * where deploy outcomes are sent.
 */
class SettingsCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:settings
                            {action=show : show | set}
                            {--repo= : Repository URL, e.g. git@github.com:org/app.git ("" to clear)}
                            {--branch= : Default branch}
                            {--keep-releases= : Releases to keep on disk (1-50)}
                            {--shared-path=* : in_release:on_server, repeatable; replaces the whole list}
                            {--clear-shared-paths : Drop the stored list and fall back to config}
                            {--notify-success= : 1/0 — notify on successful deploys}
                            {--notify-failure= : 1/0 — notify on failed deploys}
                            {--mail= : Notification address ("" to clear)}
                            {--slack-url= : Slack incoming webhook URL ("" to clear)}
                            {--discord-url= : Discord webhook URL ("" to clear)}
                            {--webhook-url= : Generic JSON webhook URL ("" to clear)}
                            {--relay= : on/off — fan a provider webhook out to peers with a base URL}
                            {--json : Machine-readable output (show)}';

    protected $description = 'Show or change deploy settings — the Settings page and the relay switch';

    public function handle(SettingsRepository $settings): int
    {
        try {
            return match ($this->argument('action')) {
                'show' => $this->show($settings),
                'set' => $this->set($settings),
                default => $this->unknownAction(),
            };
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function show(SettingsRepository $settings): int
    {
        $pci = Slipstream::pciMode();
        $relay = filter_var($settings->get('relay_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $paths = $settings->sharedPaths();
        $storedPaths = $settings->get('shared_paths');

        $secrets = [];
        foreach (['notification_slack_url' => 'slack_url', 'notification_discord_url' => 'discord_url', 'notification_webhook_url' => 'webhook_url'] as $key => $label) {
            $value = $settings->get($key);
            $secrets[$label] = is_string($value) && $value !== '';
        }

        if ($this->option('json')) {
            $this->json([
                'repository' => $settings->repoUrl(),
                'branch' => $settings->branch(),
                'keep_releases' => $settings->keepReleases(),
                'shared_paths' => $paths,
                'shared_paths_source' => is_array($storedPaths) && $storedPaths !== [] ? 'settings' : 'config',
                'notify_on_success' => filter_var($settings->get('notify_on_success', true), FILTER_VALIDATE_BOOLEAN),
                'notify_on_failure' => filter_var($settings->get('notify_on_failure', true), FILTER_VALIDATE_BOOLEAN),
                'notification_mail' => $settings->get('notification_mail'),
                'notification_slack_url_set' => $secrets['slack_url'],
                'notification_discord_url_set' => $secrets['discord_url'],
                'notification_webhook_url_set' => $secrets['webhook_url'],
                'relay_enabled' => $relay,
                'relay_effective' => $pci ? false : $relay,
                'fleet_key_separate' => $settings->hasSeparatePeerSecret(),
                'pci_mode' => $pci,
            ]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Repository', $settings->repoUrl() ?: '<fg=yellow>not set</> (slipstream:settings set --repo=…)');
        $this->components->twoColumnDetail('Branch', $settings->branch());
        $this->components->twoColumnDetail('Keep releases', (string) $settings->keepReleases());
        $this->components->twoColumnDetail(
            'Shared paths',
            $paths === [] ? 'none beyond .env and storage' : implode(', ', array_map(fn ($in, $on) => "{$in} → {$on}", array_keys($paths), $paths))
            .(is_array($storedPaths) && $storedPaths !== [] ? '' : ' (from config)')
        );
        $this->components->twoColumnDetail('Notify on success', filter_var($settings->get('notify_on_success', true), FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Notify on failure', filter_var($settings->get('notify_on_failure', true), FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Mail', (string) ($settings->get('notification_mail') ?: '—'));
        $this->components->twoColumnDetail('Slack webhook', $secrets['slack_url'] ? 'set' : '—');
        $this->components->twoColumnDetail('Discord webhook', $secrets['discord_url'] ? 'set' : '—');
        $this->components->twoColumnDetail('Generic webhook', $secrets['webhook_url'] ? 'set' : '—');
        $this->components->twoColumnDetail('Relay', ($relay ? 'on' : 'off').($pci && $relay ? ' (ignored: PCI mode)' : ''));
        $this->components->twoColumnDetail('Fleet key', $settings->hasSeparatePeerSecret() ? 'separate from the webhook secret' : 'falls back to the webhook secret');
        $this->components->twoColumnDetail('PCI mode', $pci ? 'on' : 'off');

        return self::SUCCESS;
    }

    protected function set(SettingsRepository $settings): int
    {
        $changed = [];

        if (($repo = $this->option('repo')) !== null) {
            $repo = trim((string) $repo);
            if (mb_strlen($repo) > 500) {
                throw new InvalidArgumentException('--repo is too long.');
            }
            $settings->set('repo_url', $repo !== '' ? $repo : null);
            $changed['repo_url'] = $repo !== '' ? $repo : '(cleared)';
        }

        if (($branch = $this->option('branch')) !== null) {
            $branch = trim((string) $branch);
            $validator = Validator::make(['branch' => $branch], ['branch' => ['required', 'string', 'max:255', GitRef::rule()]]);
            if ($validator->fails()) {
                throw new InvalidArgumentException('--branch: '.implode(' ', $validator->errors()->all()));
            }
            $settings->set('branch', $branch);
            $changed['branch'] = $branch;
        }

        if (($keep = $this->option('keep-releases')) !== null) {
            if (! ctype_digit((string) $keep) || (int) $keep < 1 || (int) $keep > 50) {
                throw new InvalidArgumentException('--keep-releases must be a whole number from 1 to 50.');
            }
            $settings->set('keep_releases', (string) (int) $keep);
            $changed['keep_releases'] = (string) (int) $keep;
        }

        if ($this->option('clear-shared-paths')) {
            $settings->forget('shared_paths');
            $changed['shared_paths'] = '(cleared — config applies)';
        } elseif (($pairs = array_filter((array) $this->option('shared-path'))) !== []) {
            $paths = $this->sharedPaths($pairs);
            $settings->set('shared_paths', $paths);
            $changed['shared_paths'] = implode(', ', array_map(fn ($in, $on) => "{$in} → {$on}", array_keys($paths), $paths));
        }

        foreach (['notify-success' => 'notify_on_success', 'notify-failure' => 'notify_on_failure'] as $option => $key) {
            if (($value = $this->flag($option)) !== null) {
                $settings->set($key, $value ? '1' : '0');
                $changed[$key] = $value ? 'yes' : 'no';
            }
        }

        if (($mail = $this->option('mail')) !== null) {
            $mail = trim((string) $mail);
            if ($mail !== '' && Validator::make(['mail' => $mail], ['mail' => 'email|max:255'])->fails()) {
                throw new InvalidArgumentException('--mail must be an email address.');
            }
            $settings->set('notification_mail', $mail !== '' ? $mail : null);
            $changed['notification_mail'] = $mail !== '' ? $mail : '(cleared)';
        }

        foreach (['slack-url' => 'notification_slack_url', 'discord-url' => 'notification_discord_url', 'webhook-url' => 'notification_webhook_url'] as $option => $key) {
            if (($url = $this->option($option)) === null) {
                continue;
            }
            $url = trim((string) $url);
            if ($url !== '' && Validator::make(['url' => $url], ['url' => 'url|max:500'])->fails()) {
                throw new InvalidArgumentException("--{$option} must be a full URL.");
            }
            $settings->set($key, $url !== '' ? $url : null, encrypted: true);
            $changed[$key] = $url !== '' ? 'set' : '(cleared)';
        }

        if (($relay = $this->flag('relay')) !== null) {
            $settings->set('relay_enabled', $relay ? '1' : '0');
            $changed['relay_enabled'] = $relay ? 'on' : 'off';
            Activity::log('webhook.relay_'.($relay ? 'enabled' : 'disabled'), ['via' => 'cli']);
            if ($relay && Slipstream::pciMode()) {
                $this->components->warn('Stored for the fleet, but this node is in PCI mode and never fans out.');
            }
        }

        if ($changed === []) {
            throw new InvalidArgumentException('Nothing to set — see `slipstream:settings --help` for the options.');
        }

        Activity::log('settings.saved', ['via' => 'cli', 'changed' => array_keys($changed)]);

        foreach ($changed as $key => $value) {
            $this->components->twoColumnDetail($key, $value);
        }
        $this->components->info('Settings saved.');

        return self::SUCCESS;
    }

    /**
     * "in_release:on_server" pairs, validated like the page's rows.
     *
     * @param  list<string>  $pairs
     * @return array<string, string>
     */
    protected function sharedPaths(array $pairs): array
    {
        $paths = [];

        foreach ($pairs as $pair) {
            $parts = explode(':', (string) $pair, 2);
            if (count($parts) !== 2) {
                throw new InvalidArgumentException("--shared-path expects in_release:on_server, got \"{$pair}\".");
            }

            $in = SharedPaths::normalize($parts[0]);
            $on = SharedPaths::normalize($parts[1]);

            if (SharedPaths::isUnsafe($in) || SharedPaths::isUnsafe($on)) {
                throw new InvalidArgumentException("\"{$pair}\": paths must be relative (no leading slash) and must not contain \"..\".");
            }
            if (isset($paths[$in])) {
                throw new InvalidArgumentException("Duplicate release path: {$in}");
            }

            $paths[$in] = $on;
        }

        return $paths;
    }

    protected function unknownAction(): int
    {
        $this->components->error('Unknown action "'.$this->argument('action').'". Use: show, set.');

        return self::FAILURE;
    }
}
