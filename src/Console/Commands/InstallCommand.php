<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Slipstream\Database\Tables;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Diagnostics\ComposerLockCheck;
use Slipstream\Environment\EnvContentMerger;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Release\DeployRef;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

class InstallCommand extends Command
{
    protected $signature = 'slipstream:install
                            {--force : Overwrite existing published config}
                            {--pci : Enable PCI mode on this server: provider webhook only — no dashboard, .env editor, env sync, relay or fleet queue}';

    protected $description = 'Install Slipstream: publish config/assets, migrate, seed defaults';

    public function handle(SettingsRepository $settings, Tables $tables): int
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

        $configured = Slipstream::databaseConnection();
        if ($configured !== null && ! $tables->defined($configured)) {
            $this->components->error("SLIPSTREAM_DB_CONNECTION names \"{$configured}\", which config/database.php does not define. Add the connection (the README has a SQLite example), or unset the variable.");

            return self::FAILURE;
        }

        // A SQLite file named by SLIPSTREAM_DB_CONNECTION may not exist yet,
        // and `migrate` only offers to create one for its own connection.
        $connection = $tables->activeConnection();
        $tables->ensureSqliteFile($connection);

        // Run ONLY Slipstream's migrations, not the host app's whole pending
        // migration set — install shouldn't surprise-migrate the application.
        // They create their tables on Slipstream's connection.
        $migrations = realpath(__DIR__.'/../../../database/migrations');
        $this->call('migrate', [
            '--path' => $migrations,
            '--realpath' => true,
            '--force' => true,
        ]);

        // Migrations recorded as run while the tables are missing here means
        // SLIPSTREAM_DB_CONNECTION was pointed somewhere new after install.
        if (! Slipstream::schema()->hasTable('slipstream_settings') && ! $this->createTablesOn($tables, $connection)) {
            return self::FAILURE;
        }

        // On its own connection this node's schema is not tracked by the
        // app's (shared) migrations table, so bring it current here as well.
        $tables->ensureCurrentIfOwnConnection();

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
        // PCI mode seeds them too: they are the build, not an extra. A release
        // that carries a hooks file needs none of them.
        $hooks = DefaultHooks::seedFor(base_path());

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

        // Poll mode has no webhook, so it needs no secret to share with a git host.
        $hadSecret = Slipstream::pollMode() || $settings->webhookSecret() !== null;
        $secret = Slipstream::pollMode() ? null : $settings->ensureWebhookSecret();
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
        $database = "Database:  {$connection} ({$tables->describe($connection)})";
        try {
            $trigger = Slipstream::pollMode()
                ? 'Trigger:   poll · '.DeployRef::fromConfig()->describe().' (no webhook route)'
                : "Webhook:   /{$path}/hooks/deploy".($pci ? ' (the only route registered in PCI mode)' : '');
        } catch (\Throwable $e) {
            $trigger = 'Trigger:   poll · invalid SLIPSTREAM_DEPLOY_REF: '.$e->getMessage();
        }

        $this->components->info('Slipstream installed.');
        $this->components->bulletList($pci ? [
            $trigger,
            'PCI mode:  on — no dashboard, .env editor, env sync, relay or fleet queue on this server',
            $database,
            "Hooks:     {$hooks}",
            'Next:      php artisan slipstream:provision, then php artisan slipstream:doctor',
        ] : [
            "Dashboard: /{$path} (local-only until you define Slipstream::auth or viewSlipstream gate)",
            $trigger,
            $database,
            "Hooks:     {$hooks}",
            'Next:      php artisan slipstream:provision  (server bootstrap)',
        ]);

        if ($pci && $tables->sameDatabase($connection, $tables->defaultConnection())) {
            $this->components->warn('Deployments, heartbeats and webhook deliveries are being written to the app database. To keep them out of it, give Slipstream its own: SLIPSTREAM_DB_CONNECTION (README → PCI mode).');
        }

        $this->warnIfNotCommitted();

        return self::SUCCESS;
    }

    /**
     * Create the schema on a connection the migrator already counts as done.
     * Refused while the default connection still holds a used set: the
     * history, hooks, pipelines and webhook secret are there, and seeding a
     * fresh set here would leave the copy nowhere to go. An empty old set has
     * nothing worth keeping.
     */
    protected function createTablesOn(Tables $tables, string $connection): bool
    {
        $default = $tables->defaultConnection();

        try {
            $oldTablesThere = ! $tables->sameDatabase($connection, $default)
                && in_array('slipstream_settings', $tables->presentOn($default), true)
                && DB::connection($default)->table('slipstream_settings')->exists();
        } catch (\Throwable) {
            $oldTablesThere = false;
        }

        if ($oldTablesThere) {
            $this->components->error("Slipstream's tables are on \"{$default}\", but SLIPSTREAM_DB_CONNECTION points at \"{$connection}\", which has none yet.");
            $this->line('  Copy them across first — history, hooks, pipelines and the webhook secret come along:');
            $this->line("  php artisan slipstream:database copy --from={$default} --to={$connection}");

            return false;
        }

        $tables->createOn($connection);
        $this->components->info("Created Slipstream's tables on \"{$connection}\".");

        return true;
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
