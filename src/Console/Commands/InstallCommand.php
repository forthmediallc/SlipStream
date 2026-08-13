<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Diagnostics\ComposerLockCheck;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;

class InstallCommand extends Command
{
    protected $signature = 'slipstream:install
                            {--force : Overwrite existing published config}';

    protected $description = 'Install Slipstream: publish config/assets, migrate, seed defaults';

    public function handle(SettingsRepository $settings): int
    {
        $this->components->info('Installing Slipstream…');

        $this->call('vendor:publish', [
            '--tag' => 'slipstream-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'slipstream-assets',
            '--force' => true,
        ]);

        // Run ONLY Slipstream's migrations, not the host app's whole pending
        // migration set — install shouldn't surprise-migrate the application.
        $migrations = realpath(__DIR__.'/../../../database/migrations');
        $this->call('migrate', [
            '--path' => $migrations,
            '--realpath' => true,
            '--force' => true,
        ]);

        Pipeline::query()->firstOrCreate(
            ['name' => 'main'],
            [
                'branch_pattern' => config('slipstream.deploy.branch', 'main'),
                'auto_deploy' => true,
                'enabled' => true,
                'server_names' => null,
            ]
        );

        // Seed the default deploy hooks (build/migrate/optimize/storage/horizon/
        // reverb). All editable in the dashboard — nothing is locked to them.
        DefaultHooks::seed();

        Server::query()->updateOrCreate(
            ['server_name' => Slipstream::serverName()],
            [
                'hostname' => gethostname() ?: null,
                'php_version' => PHP_VERSION,
                'fpm_service' => config('slipstream.services.fpm'),
                'horizon_program' => config('slipstream.services.horizon'),
                'reverb_program' => config('slipstream.services.reverb'),
                'last_heartbeat_at' => now(),
            ]
        );

        $hadSecret = $settings->webhookSecret() !== null;
        $secret = $settings->ensureWebhookSecret();
        if (! $hadSecret) {
            // Fresh install: give the fleet its own signing key from day one, so
            // the secret shared with the git host is never the trust root for
            // relay/env-sync. Deliberately NOT done when a webhook secret
            // already existed — on an existing fleet that would reject peers
            // still running the pre-split version until every node upgrades.
            $settings->ensurePeerSecret();

            $this->newLine();
            // Only echo the secret to an interactive TTY — printing it in a
            // non-interactive run (CI) would leak it into build logs.
            if ($this->input->isInteractive()) {
                $this->components->warn('Webhook HMAC secret (store this in GitHub — shown once; reveal later in dashboard):');
                $this->line('  '.$secret);
            } else {
                $this->components->info('Webhook HMAC secret generated. Reveal it in the dashboard → Webhook.');
            }
            $this->newLine();
        }

        $path = Slipstream::path();
        $this->components->info('Slipstream installed.');
        $this->components->bulletList([
            "Dashboard: /{$path} (local-only until you define Slipstream::auth or viewSlipstream gate)",
            "Webhook:   /{$path}/hooks/deploy",
            'Next:      php artisan slipstream:provision  (server bootstrap)',
        ]);

        $this->warnIfNotCommitted();

        return self::SUCCESS;
    }

    /**
     * Deploys build from git, so a package that only exists in this working
     * directory is not in the release. Silent outside a git repo.
     */
    protected function warnIfNotCommitted(): void
    {
        $check = new ComposerLockCheck(base_path());

        if (! $check->isGitRepo()) {
            return;
        }

        if ($check->composerFilesDirty() || $check->committedLockHasPackage() === false) {
            $this->newLine();
            $this->components->warn(ComposerLockCheck::commitFirstMessage());
        }
    }
}
