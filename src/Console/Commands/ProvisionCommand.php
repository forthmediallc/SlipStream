<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Deploy\AdoptInPlace;
use Slipstream\Diagnostics\FpmPool;
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
                            {--skip-clone : Do not clone the bare mirror}
                            {--fpm-user= : PHP-FPM pool user (default: detected from the pool config)}
                            {--adopt : Adopt an existing checkout as the first release without asking}
                            {--no-adopt : Never adopt the checkout}';

    protected $description = 'One-time server bootstrap: deploy key, bare mirror, directory layout';

    public function handle(SettingsRepository $settings): int
    {
        $root = Slipstream::appRoot();
        $this->components->info("Provisioning Slipstream layout at {$root}");

        $this->createLayout($root);

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
        $this->checkPoolUser($root);

        $env = $root.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($env)) {
            $this->components->warn("Shared .env not found at {$env}");
            $this->line('  Create it once with production values before the first deploy.');
        } else {
            $this->components->twoColumnDetail('Shared .env', '<fg=green>present</>');
        }

        $adopted = $this->maybeAdopt($root, $settings);

        $this->newLine();
        if ($adopted) {
            $this->components->info('Provision complete. One manual step, whenever you are ready:');
            $this->components->bulletList([
                'nginx: change  root '.$root.'/public;  →  root '.$root.'/current/public;',
                'add the $realpath_root fastcgi params — see '.$this->nginxStubPath(),
                'sudo nginx -t && sudo systemctl reload nginx',
            ]);
            $this->line('  Both paths serve identical bytes right now, so the flip is zero-downtime.');
            $this->line('  The app files left at '.$root.' are inert once it is done.');
            $this->newLine();
            $this->line('  Then: php artisan slipstream:deploy   (builds a fresh release from git)');
        } else {
            $this->components->info('Provision complete. First deploy:');
            $this->line('  php artisan slipstream:deploy');
            $this->newLine();
            $this->line('  Web server: point the document root at '.$root.'/current/public and add the');
            $this->line('  $realpath_root fastcgi params — see '.$this->nginxStubPath());
        }

        $this->line('  Verify the whole setup with: php artisan slipstream:doctor');

        return self::SUCCESS;
    }

    /**
     * Turn a live plain checkout into the release layout, so the operator never
     * has to choose between a document root that does not exist yet and a
     * deploy with no signal for when it is safe to flip.
     */
    protected function maybeAdopt(string $root, SettingsRepository $settings): bool
    {
        if ($this->option('no-adopt')) {
            return false;
        }

        $sharedPaths = $settings->sharedPaths();
        $adopter = new AdoptInPlace($root, $sharedPaths);

        if ($adopter->currentIsRealDirectory()) {
            $this->newLine();
            $this->components->error('current exists as a real directory at '.$adopter->currentPath());
            $this->line('  The deploy swap replaces it with `mv -T`, which fails against a real directory.');
            $this->line('  Remove or rename it, then run provision again.');

            return false;
        }

        if (! $adopter->isPlainCheckout()) {
            // Explain a declined --adopt; silence here reads as "adopted".
            if ($this->option('adopt')) {
                $this->newLine();
                $this->components->warn('Nothing to adopt.');
                $this->line($adopter->whyNotAdoptable());
            }

            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->components->warn('Skipping adopt-in-place: it targets the production Linux layout.');

            return false;
        }

        if (! $this->option('adopt')) {
            if (! $this->input->isInteractive()) {
                $this->newLine();
                $this->components->warn('This looks like a plain checkout with no release layout.');
                $this->line('  Re-run with --adopt to convert it into the first release without downtime.');

                return false;
            }

            $this->newLine();
            $this->line('  This looks like a plain checkout serving from '.$root.'/public.');
            $this->line('  Adopting copies it as-is into releases/ and links current at it — no build,');
            $this->line('  no downtime, and the document root can be flipped afterwards at your leisure.');

            if (! $this->confirm('Adopt this checkout as the first release?', true)) {
                return false;
            }
        }

        try {
            $release = $adopter->adopt();
        } catch (\Throwable $e) {
            $this->components->error('Adopt failed: '.$e->getMessage());

            return false;
        }

        $this->components->info('Adopted this checkout as '.$release);

        foreach ($sharedPaths as $releaseRelative => $sharedRelative) {
            $this->line("  shared path {$releaseRelative} → {$sharedRelative} (moved into shared; both roots now link to it)");
        }

        return true;
    }

    protected function nginxStubPath(): string
    {
        $path = dirname(__DIR__, 3).'/stubs/nginx-slipstream.conf';

        return realpath($path) ?: $path;
    }

    /**
     * `releases/` must be traversable by the web server, which is usually a
     * different user than whoever runs provision: at 0750 nginx cannot resolve
     * a path inside it. Deploy logs stay 0750 — they hold raw hook output.
     */
    protected function createLayout(string $root): void
    {
        foreach (['releases' => 0755, 'deploy/logs' => 0750] as $dir => $mode) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dir);

            if (! is_dir($path)) {
                mkdir($path, $mode, true);
                // mkdir's mode is masked by the umask, so set it explicitly.
                @chmod($path, $mode);
                $this->line("  created {$path}");
            }
        }

        $this->repairReleasesTraversal($root.DIRECTORY_SEPARATOR.'releases');
    }

    /**
     * An earlier provision created releases/ at 0750; adding the traverse bit
     * here saves an operator the "No input file specified" hunt.
     *
     * Only the execute bits are ever added — the same rule doctor checks by.
     * Anything more opinionated would quietly undo deliberate setups: forcing
     * 0755 strips setgid/group-write off a 2775 layout, and "fixing" a
     * hardened 0711 (traversable, not listable) would loosen a directory that
     * already works.
     */
    protected function repairReleasesTraversal(string $releases): void
    {
        $current = @fileperms($releases);

        if ($current === false || ($current & 0o001) === 0o001) {
            return;
        }

        $widened = ($current & 07777) | 0o011;
        if (@chmod($releases, $widened)) {
            $this->line(sprintf('  added traverse bits on %s (%04o → %04o)', $releases, $current & 07777, $widened));
        }
    }

    /**
     * Warn when PHP-FPM runs as someone else: every file this run creates would
     * then be unwritable by the app, and the resulting failures (blank 500s,
     * empty laravel.log, "No input file specified") point nowhere near here.
     */
    protected function checkPoolUser(string $root): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $poolUser = $this->option('fpm-user') ?: FpmPool::detectUser();
        $current = FpmPool::currentUser();

        if (! $poolUser) {
            $candidates = FpmPool::detectUsers();

            if (count($candidates) > 1) {
                $this->components->twoColumnDetail('FPM pool user', '<fg=yellow>ambiguous</>');
                $this->line('  Pools run as '.implode(', ', $candidates).' — pass --fpm-user=<user> to say');
                $this->line('  which one serves this app.');
            } else {
                $this->components->twoColumnDetail('FPM pool user', '<fg=yellow>not detected</>');
                $this->line('  Pass --fpm-user=<user> so provision can check ownership against it.');
            }

            return;
        }

        $this->components->twoColumnDetail(
            'FPM pool user',
            $poolUser === $current ? '<fg=green>'.$poolUser.'</>' : '<fg=yellow>'.$poolUser.'</>'
        );

        if ($current === null || $poolUser === $current) {
            return;
        }

        $this->newLine();
        $this->components->warn("PHP-FPM runs as '{$poolUser}' but you are running this as '{$current}'.");
        $this->line("  Dashboard and webhook deploys run as '{$poolUser}', and a deploy writes repo.git (fetch),");
        $this->line("  releases/ and deploy/. Everything this run created is owned by '{$current}', so the");
        $this->line("  first deploy would fail at git fetch with 'insufficient permission for adding an object'.");
        $this->line("  Hand the layout to '{$poolUser}' now:");

        // Everything a deploy writes, but only what exists — so the command
        // pastes as-is. The mirror is the one that bites first (git fetch),
        // and mixed ownership inside it is the hard-to-see variant, so a
        // recursive chown of the whole thing is the honest fix.
        $owned = array_values(array_filter(
            array_map(fn (string $dir) => $root.'/'.$dir, ['repo.git', 'releases', 'deploy', 'storage']),
            'is_dir'
        ));
        if ($owned !== []) {
            $this->line(sprintf('    sudo chown -R %s:%s %s', $poolUser, $poolUser, implode(' ', $owned)));
        }

        $this->line("  From then on run every deploy as '{$poolUser}' (sudo -u {$poolUser} …), never as '{$current}':");
        $this->line('  one root-run git fetch leaves root-owned directories inside repo.git/objects.');

        $this->noteDeployKeyOwner($current, $poolUser);
        $this->newLine();
    }

    /**
     * A key generated by this run lives in THIS user's ~/.ssh — unreadable by
     * the pool user, whose fetches will then fail with "Permission denied
     * (publickey)" the moment the ownership problem above is fixed.
     */
    protected function noteDeployKeyOwner(string $current, string $poolUser): void
    {
        $home = getenv('HOME') ?: (function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['dir'] ?? null)
            : null);
        $key = $home ? $home.'/.ssh/'.(string) $this->option('key-name') : null;

        if ($key && is_file($key)) {
            $this->line("  The deploy key at {$key} belongs to '{$current}' too — '{$poolUser}' needs its own:");
            $this->line("    sudo -u {$poolUser} php artisan slipstream:provision --skip-clone   (generates one for {$poolUser})");
        }
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

            // An artifact node downloads what CI built: no build tool is needed,
            // and on a PCI node none should be there.
            if (Slipstream::artifactMode()) {
                $this->components->twoColumnDetail($bin, $ok ? '<fg=yellow>found — not needed in artifact mode</>' : 'not needed (artifact mode)');

                continue;
            }

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
