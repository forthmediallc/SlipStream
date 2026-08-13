<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Sudo;
use Symfony\Component\Process\Process;

class ProvisionCommand extends Command
{
    protected $signature = 'slipstream:provision
                            {--repo= : Git clone URL (e.g. git@github.com:org/app.git)}
                            {--key-name=id_ed25519_slipstream : Deploy key filename under ~/.ssh}
                            {--skip-key : Do not generate a deploy key}
                            {--skip-clone : Do not clone the bare mirror}';

    protected $description = 'One-time server bootstrap: deploy key, bare mirror, directory layout';

    public function handle(SettingsRepository $settings): int
    {
        $root = Slipstream::appRoot();
        $this->components->info("Provisioning Slipstream layout at {$root}");

        foreach (['releases', 'deploy/logs'] as $dir) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dir);
            if (! is_dir($path)) {
                mkdir($path, 0750, true);
                $this->line("  created {$path}");
            }
        }

        if (! $this->option('skip-key') && DIRECTORY_SEPARATOR !== '\\') {
            $this->generateDeployKey();
        }

        $repoUrl = $this->option('repo') ?: $settings->repoUrl();
        if (! $repoUrl && ! $this->option('skip-clone')) {
            $repoUrl = $this->ask('Git clone URL (read-only deploy key recommended)');
        }

        if ($repoUrl && ! $this->option('skip-clone')) {
            $settings->set('repo_url', $repoUrl);
            $mirror = $root.DIRECTORY_SEPARATOR.'repo.git';
            if (is_dir($mirror)) {
                $this->components->warn("Bare mirror already exists at {$mirror} — skipping clone.");
            } else {
                $this->components->info("Cloning bare mirror → {$mirror}");
                $process = new Process(['git', 'clone', '--mirror', $repoUrl, $mirror]);
                $process->setTimeout(600);
                $process->run(fn ($type, $buf) => $this->output->write($buf));
                if (! $process->isSuccessful()) {
                    $this->error('git clone failed: '.$process->getErrorOutput());

                    return self::FAILURE;
                }
            }
        }

        $this->checkToolchain();

        $env = $root.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($env)) {
            $this->components->warn("Shared .env not found at {$env}");
            $this->line('  Create it once with production values before the first deploy.');
        } else {
            $this->components->twoColumnDetail('Shared .env', '<fg=green>present</>');
        }

        $this->newLine();
        $this->components->info('Provision complete. First deploy:');
        $this->line('  php artisan slipstream:deploy');

        return self::SUCCESS;
    }

    protected function generateDeployKey(): void
    {
        $home = getenv('HOME') ?: (function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['dir'] ?? null)
            : null);

        if (! $home) {
            $this->components->warn('HOME not set — skip deploy key generation.');

            return;
        }

        $sshDir = $home.'/.ssh';
        if (! is_dir($sshDir)) {
            mkdir($sshDir, 0700, true);
        }

        $name = (string) $this->option('key-name');
        $keyPath = $sshDir.'/'.$name;
        $pubPath = $keyPath.'.pub';

        if (is_file($keyPath)) {
            $this->components->warn("Deploy key already exists: {$keyPath}");
        } else {
            $host = gethostname() ?: 'slipstream';
            $process = new Process([
                'ssh-keygen', '-t', 'ed25519', '-N', '', '-C', "deploy@{$host}", '-f', $keyPath,
            ]);
            $process->run();
            if (! $process->isSuccessful()) {
                $this->error('ssh-keygen failed: '.$process->getErrorOutput());

                return;
            }
            $this->components->info("Generated deploy key: {$keyPath}");
        }

        if (is_file($pubPath)) {
            $this->newLine();
            $this->components->warn('Add this public key as a read-only GitHub deploy key:');
            $this->line(trim((string) file_get_contents($pubPath)));
            $this->newLine();
        }
    }

    protected function checkToolchain(): void
    {
        foreach (['git', 'composer', 'node', 'npm'] as $bin) {
            $process = new Process(
                DIRECTORY_SEPARATOR === '\\'
                    ? ['where', $bin]
                    : ['which', $bin]
            );
            $process->run();
            $ok = $process->isSuccessful();
            $this->components->twoColumnDetail(
                $bin,
                $ok ? '<fg=green>found</>' : '<fg=red>missing</>'
            );
        }

        $fpm = (string) config('slipstream.services.fpm', 'php8.4-fpm');
        if (DIRECTORY_SEPARATOR !== '\\') {
            $process = new Process(['systemctl', 'is-enabled', $fpm]);
            $process->run();
            $this->components->twoColumnDetail(
                'fpm ('.$fpm.')',
                $process->isSuccessful() ? '<fg=green>enabled</>' : '<fg=yellow>check manually</>'
            );

            $this->checkSudoReload($fpm);
        }
    }

    /**
     * Deploys reload FPM with `sudo -n`, so a missing NOPASSWD rule is worth
     * finding here rather than at the end of the first deploy.
     */
    protected function checkSudoReload(string $fpm): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return;
        }

        $allowed = Sudo::canRunWithoutPassword(['systemctl', 'reload', $fpm]);

        if ($allowed === null) {
            return;
        }

        $this->components->twoColumnDetail(
            'sudo systemctl reload',
            $allowed ? '<fg=green>permitted</>' : '<fg=yellow>needs a password</>'
        );

        if (! $allowed) {
            $this->components->warn('Deploys cannot reload FPM without a password prompt.');
            $this->line('  Install the sudoers rule as /etc/sudoers.d/slipstream (chmod 440) before the first deploy:');
            $this->line('    '.Sudo::stubPath());
            $this->line('  Without it the new release is swapped in but FPM keeps serving cached old code.');
        }
    }
}
