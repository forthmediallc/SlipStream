<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Slipstream\Deploy\FailedReleases;
use Slipstream\Diagnostics\CheckResult;
use Slipstream\Diagnostics\ComposerLockCheck;
use Slipstream\Diagnostics\FpmPool;
use Slipstream\Models\Server;
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
    protected $signature = 'slipstream:doctor
                            {--fpm-user= : PHP-FPM pool user (default: detected from the pool config)}';

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

    /**
     * Informational only — no remediation command is printed from here.
     *
     * Ownership of releases/deploy by another user is a SUPPORTED layout (a
     * deploy user owns the tree, the pool user reaches storage through the
     * web_group), and a recursive chown printed from a glob-order guess at the
     * pool user is a paste-ready outage. The one place ownership genuinely
     * bites — the pool user unable to write storage — is checkStorageWritable,
     * which tests the actual behaviour and scopes its fix to storage.
     */
    protected function checkPoolUser(string $root): CheckResult
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return CheckResult::skip('FPM pool user', 'not applicable on Windows');
        }

        $pool = $this->poolUser();
        $current = FpmPool::currentUser();

        if (! $pool) {
            $candidates = FpmPool::detectUsers();

            if (count($candidates) > 1) {
                return CheckResult::warn(
                    'FPM pool user',
                    'ambiguous — pools run as '.implode(', ', $candidates),
                    'Several FPM pools declare different users; pass --fpm-user=<user> to say which one serves this app.'
                );
            }

            return CheckResult::skip('FPM pool user', 'no pool config found to read');
        }

        if ($current !== null && $current !== $pool) {
            return CheckResult::warn(
                'FPM pool user',
                "{$pool} (you are {$current})",
                "Run deploys as {$pool}, or make sure what they create stays reachable by {$pool} "
                .'(the storage check below tests the case that actually breaks the app).'
            );
        }

        return CheckResult::pass('FPM pool user', $pool);
    }

    /**
     * The pool user this run should reason about: the explicit override, else
     * unambiguous detection.
     */
    protected function poolUser(): ?string
    {
        $override = $this->option('fpm-user');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        return FpmPool::detectUser();
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
            // The usual cause: the release current pointed at failed its
            // deploy and was preserved under a new name. Without this hint the
            // directory the operator is told to inspect looks deleted.
            $pointedAt = readlink($current) ?: '';
            if ($pointedAt !== '' && is_dir($pointedAt.FailedReleases::SUFFIX)) {
                return CheckResult::fail(
                    'current symlink',
                    'dangling — its release was preserved as '.basename($pointedAt).FailedReleases::SUFFIX,
                    'That release failed its deploy after the swap. Deploy again or roll back; the preserved '
                    .'directory is there to debug in, not to serve.'
                );
            }

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

        // Traversal needs only the execute bit — a hardened 0711 (traversable,
        // not listable) is a correct setup and must not be "fixed". The whole
        // path the web server resolves matters, not just releases/ itself: a
        // deploy under a strict umask can leave the release or its public/
        // untraversable while releases/ looks fine.
        $opaque = array_filter(
            [$releases, ...$this->livePathsToTraverse($root)],
            fn (string $path) => is_dir($path) && ((fileperms($path) & 0o001) !== 0o001)
        );

        if ($opaque !== []) {
            return CheckResult::fail(
                'releases/ permissions',
                'the web server cannot traverse: '.implode(', ', array_map(
                    fn (string $path) => sprintf('%s (%04o)', str_replace($root.DIRECTORY_SEPARATOR, '', $path), fileperms($path) & 0777),
                    $opaque
                )),
                'chmod o+x '.implode(' ', $opaque)."\n"
                .'Symptom when wrong: $realpath_root resolves empty and the browser shows "No input file specified".'
            );
        }

        return CheckResult::pass('releases/ permissions', sprintf('%04o', fileperms($releases) & 0777));
    }

    /**
     * The directories the web server walks through to serve the live release.
     *
     * @return list<string>
     */
    protected function livePathsToTraverse(string $root): array
    {
        $target = realpath($root.DIRECTORY_SEPARATOR.'current');

        if ($target === false) {
            return [];
        }

        return [$target, $target.DIRECTORY_SEPARATOR.'public'];
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

        $pool = $this->poolUser();

        // is_writable() answers for the current process and root bypasses the
        // bits entirely, so as root the question has to be asked about the pool
        // user explicitly. Name whichever user actually got tested.
        $asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $subject = ($asRoot && $pool !== null) ? $pool : (FpmPool::currentUser() ?? 'this user');

        $unwritable = [];
        foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $sub) {
            $path = $storage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub);
            if (is_dir($path) && ! $this->writableByPool($path, $pool)) {
                $unwritable[] = $sub;
            }
        }

        if ($unwritable !== []) {
            $chown = $pool ?: '<pool-user>';
            $webGroup = (string) config('slipstream.deploy.web_group', 'www-data');

            return CheckResult::fail(
                'shared storage',
                "not writable by {$subject}: ".implode(', ', $unwritable),
                "sudo chown -R {$chown}:{$chown} {$storage}\n"
                ."or keep the owner and grant the web group (what a deploy does):\n"
                ."sudo chgrp -R {$webGroup} {$storage} && sudo chmod -R g+rwX {$storage}\n"
                .'Symptom when wrong: blank 500s and an empty laravel.log, because the logger cannot write either.'
            );
        }

        return CheckResult::pass('shared storage', 'writable');
    }

    /**
     * Whether the FPM pool user can write here.
     *
     * is_writable() answers for the *current* process, and root bypasses the
     * permission bits entirely — so a doctor run under sudo (exactly what an
     * operator reaches for when chasing a permission problem) would report
     * every directory writable while the pool user still cannot write one of
     * them. Compare owner/group/mode directly whenever the answer would
     * otherwise come from root's free pass.
     */
    protected function writableByPool(string $path, ?string $pool): bool
    {
        $runningAsRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

        if (! $runningAsRoot || $pool === null || ! function_exists('posix_getpwnam')) {
            return is_writable($path);
        }

        $owner = FpmPool::ownerOf($path);
        $perms = @fileperms($path);

        if ($perms === false) {
            return is_writable($path);
        }

        if ($owner === $pool) {
            return ($perms & 0200) === 0200;
        }

        // Not the owner: the group bit only helps if the pool user is in the
        // owning group, which is more than a mode check can settle — fall back
        // to the "other" write bit, the only one that is unambiguous.
        $group = @filegroup($path);
        $poolInfo = @posix_getpwnam($pool);

        if (is_array($poolInfo) && $group !== false && $group === ($poolInfo['gid'] ?? null)) {
            return ($perms & 0020) === 0020;
        }

        return ($perms & 0002) === 0002;
    }

    protected function checkSudoReload(string $root): CheckResult
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return CheckResult::skip('sudo systemctl reload', 'not applicable on Windows');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return CheckResult::pass('sudo systemctl reload', 'running as root, no sudo needed');
        }

        $fpm = $this->fpmService();
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

    /**
     * The FPM service a deploy actually reloads: the per-server dashboard
     * override first, then config — probing a different service than the
     * deploy runs would bless a sudoers rule the deploy then fails against.
     */
    protected function fpmService(): string
    {
        try {
            $override = Server::query()
                ->where('server_name', Slipstream::serverName())
                ->value('fpm_service');
        } catch (\Throwable) {
            $override = null;
        }

        return (is_string($override) && $override !== '')
            ? $override
            : (string) config('slipstream.services.fpm', 'php8.4-fpm');
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
        // deploy does: this key, this user, this remote. HEAD alone — asking
        // for every branch head makes a busy remote enumerate thousands of
        // refs to answer a yes/no question. GIT_TERMINAL_PROMPT=0 so a
        // credential prompt fails instead of eating the 15s timeout.
        $process = new Process(
            ['git', '--git-dir='.$mirror, 'ls-remote', '--exit-code', 'origin', 'HEAD'],
            null,
            ['GIT_TERMINAL_PROMPT' => '0']
        );
        $process->setTimeout(15);

        try {
            $process->run();
        } catch (\Throwable $e) {
            // Not a warning: every deploy shells out to git, so a git that
            // cannot start is a hard blocker, and reporting it softly would let
            // doctor finish with "No blocking problems found".
            return CheckResult::fail(
                'git mirror',
                'could not run git: '.$e->getMessage(),
                'Install git and make sure it is on PATH for the deploy user — every deploy shells out to it.'
            );
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

    /**
     * Does the code the NEXT DEPLOY installs actually contain the package?
     *
     * Answered against the bare mirror at the configured branch, because that
     * is what a deploy builds from. Asking a local checkout instead would pass
     * for a commit that was never pushed — and "committed but not pushed" is
     * the same dead end as "never committed", reached one step later.
     */
    protected function checkPackageCommitted(string $root): CheckResult
    {
        $mirror = $root.DIRECTORY_SEPARATOR.'repo.git';
        $branch = (string) config('slipstream.deploy.branch', 'main');
        $symptom = 'Symptom when wrong: the deploy dies at the health step with "There are no commands defined '
            .'in the slipstream namespace".';

        if (is_dir($mirror)) {
            [$inMirror, $ref] = (new ComposerLockCheck($root))->mirrorLockHasPackage($mirror, $branch);

            if ($inMirror === false) {
                return CheckResult::fail(
                    'package committed',
                    "the mirror's {$branch} composer.lock does not include ".ComposerLockCheck::PACKAGE,
                    ComposerLockCheck::commitFirstMessage()."\n".$symptom
                );
            }

            if ($inMirror === true) {
                return CheckResult::pass('package committed', 'present in '.$ref);
            }

            // Mirror unreadable or the branch was never pushed — fall through
            // to the local checkout, which at least catches "never committed".
        }

        $check = new ComposerLockCheck(base_path());

        if (! $check->isGitRepo()) {
            return CheckResult::skip('package committed', 'no mirror to read and this is not a git working tree');
        }

        $inLock = $check->committedLockHasPackage();

        if ($inLock === false) {
            return CheckResult::fail(
                'package committed',
                "HEAD's composer.lock does not include ".ComposerLockCheck::PACKAGE,
                ComposerLockCheck::commitFirstMessage()."\n".$symptom
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

        return CheckResult::warn(
            'package committed',
            'present in local HEAD; could not confirm it is pushed',
            "The deploy builds from {$branch} in the mirror, not from this checkout. Push before deploying."
        );
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
        } catch (\Throwable) {
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
