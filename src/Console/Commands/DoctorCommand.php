<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Slipstream\Deploy\FailedReleases;
use Slipstream\Diagnostics\CheckResult;
use Slipstream\Diagnostics\ComposerLockCheck;
use Slipstream\Diagnostics\FpmPool;
use Slipstream\Slipstream;
use Slipstream\Support\Sudo;
use Symfony\Component\Process\Process;

/**
 * Checks everything a first deploy depends on, in one place, before deploying.
 *
 * Each of these failures is cheap to detect and expensive to diagnose from its
 * symptom: a 0750 directory surfaces as "No input file specified", a package
 * missing from the committed lockfile surfaces as an artisan namespace error,
 * and a pool-user mismatch surfaces as a blank 500 with an empty laravel.log.
 */
class DoctorCommand extends Command
{
    protected $signature = 'slipstream:doctor';

    protected $description = 'Diagnose the deploy layout, permissions, git access and webhook reachability';

    public function handle(): int
    {
        $root = Slipstream::appRoot();

        $this->components->info("Checking Slipstream at {$root}");

        $checks = [
            'checkPoolUser',
            'checkCurrentSymlink',
            'checkReleasesTraversable',
            'checkSharedEnv',
            'checkStorageWritable',
            'checkSudoReload',
            'checkGitAccess',
            'checkPackageCommitted',
            'checkWebhookReachable',
        ];

        $failures = 0;

        foreach ($checks as $check) {
            try {
                $result = $this->{$check}($root);
            } catch (\Throwable $e) {
                // A crashing check must never hide the checks after it.
                $result = CheckResult::warn($check, 'check could not run: '.$e->getMessage());
            }

            $this->render($result);
            $failures += $result->isFailure() ? 1 : 0;
        }

        $this->newLine();
        if ($failures > 0) {
            $this->components->error($failures.' check'.($failures === 1 ? '' : 's').' need attention before deploying.');

            return self::FAILURE;
        }

        $this->components->info('No blocking problems found.');

        return self::SUCCESS;
    }

    protected function render(CheckResult $result): void
    {
        $this->components->twoColumnDetail(
            $result->label,
            '<fg='.$result->color().'>'.$result->message.'</>'
        );

        if ($result->fix) {
            foreach (explode("\n", $result->fix) as $line) {
                $this->line('    '.$line);
            }
        }
    }

    protected function checkPoolUser(string $root): CheckResult
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return CheckResult::skip('FPM pool user', 'not applicable on Windows');
        }

        $pool = FpmPool::detectUser();
        $current = FpmPool::currentUser();

        if (! $pool) {
            return CheckResult::skip('FPM pool user', 'no pool config found to read');
        }

        $mismatched = [];
        $owners = [];
        foreach (['releases', 'storage', 'deploy'] as $dir) {
            $path = $root.DIRECTORY_SEPARATOR.$dir;
            if (! is_dir($path)) {
                continue;
            }
            $owner = FpmPool::ownerOf($path);
            if ($owner !== null && $owner !== $pool) {
                $mismatched[] = $path;
                $owners[$owner] = true;
            }
        }

        if ($mismatched !== []) {
            return CheckResult::fail(
                'FPM pool user',
                sprintf(
                    'PHP-FPM runs as %s but %s %s owned by %s',
                    $pool,
                    implode(', ', array_map('basename', $mismatched)),
                    count($mismatched) === 1 ? 'is' : 'are',
                    implode('/', array_keys($owners))
                ),
                // Only paths that exist, so the command can be pasted as-is.
                sprintf('sudo chown -R %s:%s %s', $pool, $pool, implode(' ', $mismatched))
            );
        }

        if ($current !== null && $current !== $pool) {
            return CheckResult::warn(
                'FPM pool user',
                "{$pool} (you are {$current})",
                "Run deploys as {$pool}, or anything they create will be unwritable by the app."
            );
        }

        return CheckResult::pass('FPM pool user', $pool);
    }

    protected function checkCurrentSymlink(string $root): CheckResult
    {
        $current = $root.DIRECTORY_SEPARATOR.'current';

        if (is_dir($current) && ! is_link($current)) {
            return CheckResult::fail(
                'current symlink',
                'exists as a real directory',
                'The deploy swap replaces current with `mv -T`, which fails against a real directory. '
                ."Remove or rename {$current}, then run slipstream:provision."
            );
        }

        if (! is_link($current)) {
            return CheckResult::fail(
                'current symlink',
                'missing',
                'Nothing to point a document root at yet. Run `php artisan slipstream:provision --adopt` '
                .'to adopt this checkout as the first release without downtime.'
            );
        }

        $target = realpath($current);
        if ($target === false) {
            return CheckResult::fail(
                'current symlink',
                'dangling (points at a release that no longer exists)',
                'Run a deploy, or roll back to a release that still exists.'
            );
        }

        $releases = realpath($root.DIRECTORY_SEPARATOR.'releases');
        if ($releases !== false && ! str_starts_with($target, $releases)) {
            return CheckResult::warn('current symlink', 'resolves outside releases/: '.$target);
        }

        if (FailedReleases::isFailed($target)) {
            return CheckResult::fail(
                'current symlink',
                'points at a preserved failed release',
                'That release never passed its health check. Deploy again or roll back.'
            );
        }

        return CheckResult::pass('current symlink', '→ '.basename($target));
    }

    protected function checkReleasesTraversable(string $root): CheckResult
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return CheckResult::skip('releases/ permissions', 'not applicable on Windows');
        }

        $releases = $root.DIRECTORY_SEPARATOR.'releases';
        if (! is_dir($releases)) {
            return CheckResult::fail('releases/ permissions', 'releases/ does not exist', 'Run php artisan slipstream:provision');
        }

        $perms = fileperms($releases) & 0777;

        // The web server is usually neither the owner nor in the group, so it
        // needs the "other" execute bit just to resolve a path inside.
        if (($perms & 0o005) !== 0o005) {
            return CheckResult::fail(
                'releases/ permissions',
                sprintf('%04o — the web server cannot traverse it', $perms),
                "chmod 0755 {$releases}\n"
                .'Symptom when wrong: $realpath_root resolves empty and the browser shows "No input file specified".'
            );
        }

        return CheckResult::pass('releases/ permissions', sprintf('%04o', $perms));
    }

    protected function checkSharedEnv(string $root): CheckResult
    {
        $env = Slipstream::sharedEnvPath();

        if (! is_file($env)) {
            return CheckResult::fail(
                'shared .env',
                'missing at '.$env,
                'Every release symlinks its .env here. Create it with production values before deploying.'
            );
        }

        return CheckResult::pass('shared .env', 'present');
    }

    protected function checkStorageWritable(string $root): CheckResult
    {
        $storage = $root.DIRECTORY_SEPARATOR.'storage';

        if (! is_dir($storage)) {
            return CheckResult::fail('shared storage', 'missing at '.$storage, 'Run php artisan slipstream:provision');
        }

        $unwritable = [];
        foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $sub) {
            $path = $storage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub);
            if (is_dir($path) && ! is_writable($path)) {
                $unwritable[] = $sub;
            }
        }

        if ($unwritable !== []) {
            $pool = FpmPool::detectUser() ?: 'the pool user';

            return CheckResult::fail(
                'shared storage',
                'not writable: '.implode(', ', $unwritable),
                "sudo chown -R {$pool}:{$pool} {$storage}\n"
                .'Symptom when wrong: blank 500s and an empty laravel.log, because the logger cannot write either.'
            );
        }

        return CheckResult::pass('shared storage', 'writable');
    }

    protected function checkSudoReload(string $root): CheckResult
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return CheckResult::skip('sudo systemctl reload', 'not applicable on Windows');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return CheckResult::pass('sudo systemctl reload', 'running as root, no sudo needed');
        }

        $fpm = (string) config('slipstream.services.fpm', 'php8.4-fpm');
        $allowed = Sudo::canRunWithoutPassword(['systemctl', 'reload', $fpm]);

        if ($allowed === null) {
            return CheckResult::skip('sudo systemctl reload', 'could not probe sudo');
        }

        if (! $allowed) {
            return CheckResult::fail(
                'sudo systemctl reload',
                "not permitted without a password for {$fpm}",
                'Install '.Sudo::stubPath()." as /etc/sudoers.d/slipstream (chmod 440).\n"
                .'Symptom when wrong: the release swaps in but FPM keeps serving cached old code.'
            );
        }

        return CheckResult::pass('sudo systemctl reload', 'permitted');
    }

    protected function checkGitAccess(string $root): CheckResult
    {
        $mirror = $root.DIRECTORY_SEPARATOR.'repo.git';

        if (! is_dir($mirror)) {
            return CheckResult::fail(
                'git mirror',
                'no bare mirror at '.$mirror,
                'Run php artisan slipstream:provision --repo=git@github.com:org/app.git'
            );
        }

        // ls-remote against the mirror's own origin exercises exactly what a
        // deploy does: this key, this user, this remote.
        $process = new Process(['git', '--git-dir='.$mirror, 'ls-remote', 'origin', '-h']);
        $process->setTimeout(15);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return CheckResult::warn('git mirror', 'could not run git: '.$e->getMessage());
        }

        if (! $process->isSuccessful()) {
            return CheckResult::fail(
                'git mirror',
                'cannot reach origin',
                trim($process->getErrorOutput() ?: 'git ls-remote failed')."\n"
                .'Check the deploy key is installed on the repo and readable by this user.'
            );
        }

        return CheckResult::pass('git mirror', 'origin reachable');
    }

    protected function checkPackageCommitted(string $root): CheckResult
    {
        $cwd = is_dir($root.DIRECTORY_SEPARATOR.'current')
            ? $root.DIRECTORY_SEPARATOR.'current'
            : base_path();

        $check = new ComposerLockCheck($cwd);

        if (! $check->isGitRepo()) {
            return CheckResult::skip('package committed', 'not a git working tree');
        }

        $inLock = $check->committedLockHasPackage();

        if ($inLock === false) {
            return CheckResult::fail(
                'package committed',
                "HEAD's composer.lock does not include ".ComposerLockCheck::PACKAGE,
                ComposerLockCheck::commitFirstMessage()."\n"
                .'Symptom when wrong: the deploy dies at the health step with "There are no commands defined '
                .'in the slipstream namespace".'
            );
        }

        if ($inLock === null) {
            return CheckResult::skip('package committed', 'no committed composer.lock to read');
        }

        if ($check->composerFilesDirty()) {
            return CheckResult::warn(
                'package committed',
                'composer.json/lock have uncommitted changes',
                'Releases are built from git, so uncommitted dependency changes are not deployed.'
            );
        }

        return CheckResult::pass('package committed', 'present in HEAD');
    }

    /**
     * POST to our own hook without a signature.
     *
     * Safe by construction: DeployIngress verifies the signature before it
     * looks up a pipeline or spawns anything, so this can only ever be
     * rejected. It does leave one rejected row in the delivery log — that row
     * is the evidence the endpoint is reachable and enforcing signatures.
     */
    protected function checkWebhookReachable(string $root): CheckResult
    {
        // route() honours slipstream.domain; url() would probe app.url and
        // report a 404 for a dashboard served from its own subdomain.
        try {
            $url = route('slipstream.hooks.deploy');
        } catch (\Throwable) {
            $url = url('/'.Slipstream::path().'/hooks/deploy');
        }

        try {
            $status = Http::timeout(5)->withoutRedirecting()->post($url)->status();
        } catch (\Throwable $e) {
            return CheckResult::warn(
                'webhook endpoint',
                'could not reach '.$url,
                'Checked from this host, so a failure here may just mean app.url is not resolvable locally.'
            );
        }

        return match (true) {
            $status === 401 || $status === 403 => CheckResult::pass('webhook endpoint', 'reachable, signature enforced'),
            $status === 419 => CheckResult::fail(
                'webhook endpoint',
                '419 — CSRF middleware is intercepting the hook',
                "The host app's CSRF middleware is defeating the package's route-level exclusion. Add:\n"
                ."  \$middleware->validateCsrfTokens(except: ['".Slipstream::path()."/hooks/*']);\n"
                .'to bootstrap/app.php. GitHub deliveries fail with 419 until this is done.'
            ),
            $status === 404 => CheckResult::fail(
                'webhook endpoint',
                '404 at '.$url,
                'The route is not registered, or the web server is not routing this path to this app.'
            ),
            default => CheckResult::warn('webhook endpoint', 'unexpected status '.$status.' from '.$url),
        };
    }
}
