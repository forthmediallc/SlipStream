<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Diagnostics\ComposerLockCheck;
use Slipstream\Environment\EnvContentMerger;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

class InstallCommand extends Command
{
    protected $signature = 'slipstream:install
                            {--force : Overwrite existing published config}
                            {--pci : Enable PCI mode on this server: provider webhook only — no dashboard, .env editor, env sync, relay or fleet queue}';

    protected $description = 'Install Slipstream: publish config/assets, migrate, seed defaults';

    public function handle(SettingsRepository $settings): int
    {
        // --pci turns the mode on; a re-run on a node that already has it on
        // (SLIPSTREAM_PCI_MODE in the shared .env) keeps behaving as PCI.
        $pci = (bool) $this->option('pci') || Slipstream::pciMode();

        $this->components->info('Installing Slipstream'.($pci ? ' in PCI mode' : '').'…');

        $this->call('vendor:publish', [
            '--tag' => 'slipstream-config',
            '--force' => (bool) $this->option('force'),
        ]);

        // The dashboard does not exist in PCI mode, so neither do its assets.
        if (! $pci) {
            $this->call('vendor:publish', [
                '--tag' => 'slipstream-assets',
                '--force' => true,
            ]);
        }

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
        // PCI mode seeds them too: they are the build, not an extra.
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

        if ($this->option('pci')) {
            $this->enablePciMode();
        }

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
                $this->components->warn($pci
                    ? 'Webhook HMAC secret (store this in GitHub — shown once; no dashboard in PCI mode, see slipstream:webhook-secret):'
                    : 'Webhook HMAC secret (store this in GitHub — shown once; reveal later in dashboard):');
                $this->line('  '.$secret);
            } else {
                $this->components->info($pci
                    ? 'Webhook HMAC secret generated. Print it with: php artisan slipstream:webhook-secret --reveal'
                    : 'Webhook HMAC secret generated. Reveal it in the dashboard → Webhook.');
            }
            $this->newLine();
        }

        $path = Slipstream::path();
        $this->components->info('Slipstream installed.');
        $this->components->bulletList($pci ? [
            "Webhook:   /{$path}/hooks/deploy (the only route registered in PCI mode)",
            'PCI mode:  on — no dashboard, .env editor, env sync, relay or fleet queue on this server',
            'Next:      php artisan slipstream:provision, then php artisan slipstream:doctor',
        ] : [
            "Dashboard: /{$path} (local-only until you define Slipstream::auth or viewSlipstream gate)",
            "Webhook:   /{$path}/hooks/deploy",
            'Next:      php artisan slipstream:provision  (server bootstrap)',
        ]);

        $this->warnIfNotCommitted();

        return self::SUCCESS;
    }

    /**
     * Put SLIPSTREAM_PCI_MODE=true in the shared .env — the file every release
     * links — replacing any existing line for the key, so a re-run changes
     * nothing. The mode is env-driven on purpose: once it is on, nothing in
     * Slipstream can write this file again.
     *
     * Rewritten in place rather than through EnvEditor, whose atomic rename
     * replaces the inode: an install run as root would leave a root-owned
     * 0600 .env that PHP-FPM cannot read. Writing into the existing file
     * keeps its owner and mode.
     */
    protected function enablePciMode(): void
    {
        $path = Slipstream::sharedEnvPath();
        $existed = is_file($path);
        $merger = new EnvContentMerger;

        $contents = $existed ? @file_get_contents($path) : '';
        if ($contents === false) {
            // Unreadable is not "empty": rewriting it would wipe the file.
            throw new RuntimeException("Could not read {$path} to enable PCI mode — add SLIPSTREAM_PCI_MODE=true to it by hand.");
        }
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $lines = $contents === '' ? [] : explode("\n", rtrim($contents, "\n"));

        // dotenv takes the LAST assignment, so every line for the key is
        // replaced — leaving an earlier "=false" in place would be harmless,
        // but a later one would silently win.
        $replaced = false;
        foreach ($lines as $i => $line) {
            if ($merger->keyOf($line, ['SLIPSTREAM_PCI_MODE']) !== null) {
                $lines[$i] = 'SLIPSTREAM_PCI_MODE=true';
                $replaced = true;
            }
        }
        if (! $replaced) {
            $lines[] = 'SLIPSTREAM_PCI_MODE=true';
        }

        $final = implode("\n", $lines)."\n";

        if ($existed && $final === $contents) {
            $this->components->twoColumnDetail('PCI mode', 'already on in '.$path);

            return;
        }

        if ($existed) {
            $backup = $path.'.slipstream.bak.'.gmdate('Ymd_His');
            if (@copy($path, $backup)) {
                @chmod($backup, 0600);
            }
        }

        if (@file_put_contents($path, $final) === false) {
            throw new RuntimeException("Could not write {$path} to enable PCI mode — add SLIPSTREAM_PCI_MODE=true to it by hand.");
        }

        Activity::log('pci.enabled', ['path' => $path, 'via' => 'install']);

        $this->components->twoColumnDetail('PCI mode', 'SLIPSTREAM_PCI_MODE=true written to '.$path);

        if (! $existed) {
            $this->components->warn('That file did not exist — it now holds only this line. Add the production values before deploying.');
        }

        // A cached config or route table was built without the flag; clear
        // both so the running app picks it up now rather than at the next
        // deploy's optimize.
        $this->call('config:clear');
        $this->call('route:clear');
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
