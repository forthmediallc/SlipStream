<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Diagnostics\FpmPool;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

/**
 * The webhook secret from the command line.
 *
 * The dashboard's Webhook page reveals and rotates it; in PCI mode there is no
 * dashboard, and a secret that can only be seen once at install time cannot
 * be rotated after a leak. Shell access to the server is the bar here — the
 * same bar the shared .env already sits behind.
 */
class WebhookSecretCommand extends Command
{
    protected $signature = 'slipstream:webhook-secret
                            {--reveal : Print the secret}
                            {--rotate : Replace the secret with a new one (every git host must be updated)}
                            {--rotate-fleet-key : Give the fleet its own signing key, or replace the existing one — never printed; peers read it from the shared database}';

    protected $description = 'Show or rotate the webhook secret shared with the git host, or rotate the fleet key';

    public function handle(SettingsRepository $settings): int
    {
        $rotate = (bool) $this->option('rotate');
        $reveal = (bool) $this->option('reveal');

        if ($this->option('rotate-fleet-key')) {
            $separated = $settings->hasSeparatePeerSecret();
            $settings->rotatePeerSecret();
            Activity::log($separated ? 'webhook.fleet_key_rotated' : 'webhook.fleet_key_separated', ['via' => 'cli', 'os_user' => FpmPool::currentUser()]);
            $this->components->info($separated
                ? 'Fleet key rotated. Every node reads it from the shared database — no provider changes needed.'
                : 'Fleet key separated from the webhook secret. Every node must run a Slipstream version that knows the split, or peers still signing with the webhook secret are rejected.');

            if (! $rotate && ! $reveal) {
                return self::SUCCESS;
            }
        }

        if ($rotate) {
            $settings->rotateWebhookSecret();
            Activity::log('webhook.secret_rotated', ['via' => 'cli', 'os_user' => FpmPool::currentUser()]);
            $this->components->warn('Webhook secret rotated. Update GitHub / GitLab / Bitbucket now — deliveries signed with the old secret are rejected.');
        }

        $secret = $settings->webhookSecret();

        if ($secret === null) {
            $this->components->error('No webhook secret set. Run: php artisan slipstream:install');

            return self::FAILURE;
        }

        if ($reveal) {
            Activity::log('webhook.secret_revealed', ['via' => 'cli', 'os_user' => FpmPool::currentUser()]);
            $this->line($secret);
        } elseif (! $rotate) {
            $this->components->twoColumnDetail('Webhook secret', 'set (pass --reveal to print it)');
        } else {
            $this->components->info('Pass --reveal to print the new value.');
        }

        if (! $reveal) {
            $this->components->twoColumnDetail('Hook URL', url('/'.Slipstream::path().'/hooks/deploy'));
            $this->components->twoColumnDetail(
                'Fleet key',
                $settings->hasSeparatePeerSecret() ? 'separate from the webhook secret' : 'falls back to the webhook secret'
            );
        }

        return self::SUCCESS;
    }
}
