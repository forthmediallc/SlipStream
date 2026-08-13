<?php

namespace Slipstream\Deploy;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Slipstream\Diagnostics\ComposerLockCheck;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentHook;
use Slipstream\Models\Server;
use Slipstream\Notifications\DeployNotifier;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\GitRef;
use Slipstream\Support\Sudo;

/**
 * 1:1 port of deploy/deploy.sh step pipeline.
 */
class Deployer
{
    protected string $appRoot;

    protected string $repo;

    protected string $releases;

    protected string $current;

    protected string $sharedEnv;

    protected string $sharedStorage;

    protected string $sharedAuth;

    protected string $php;

    protected string $composer;

    protected string $npm;

    protected string $branch;

    protected int $keep;

    protected ?string $releasePath = null;

    protected ?string $sha = null;

    protected float $startedAt;

    /** @var list<string> Post-swap issues that don't fail the deploy but must surface. */
    protected array $warnings = [];

    public function __construct(
        protected Deployment $deployment,
        protected SettingsRepository $settings,
        protected EventLogger $log,
    ) {
        $this->appRoot = Slipstream::appRoot();
        $this->repo = $this->appRoot.DIRECTORY_SEPARATOR.'repo.git';
        $this->releases = $this->appRoot.DIRECTORY_SEPARATOR.'releases';
        $this->current = $this->appRoot.DIRECTORY_SEPARATOR.'current';
        $this->sharedEnv = Slipstream::sharedEnvPath();
        $this->sharedStorage = $this->appRoot.DIRECTORY_SEPARATOR.'storage';
        $this->sharedAuth = $this->appRoot.DIRECTORY_SEPARATOR.'auth.json';
        $this->php = (string) config('slipstream.deploy.php_binary', PHP_BINARY);
        $this->composer = (string) config('slipstream.deploy.composer_binary', 'composer');
        $this->npm = (string) config('slipstream.deploy.npm_binary', 'npm');
        $this->branch = $deployment->branch ?: $this->settings->branch();
        $this->keep = $this->settings->keepReleases();
        $this->startedAt = microtime(true);
    }

    public function run(): void
    {
        $lock = new DeployLock($this->appRoot);

        if (! $lock->acquire()) {
            // acquire() already recorded a pending flag; the in-progress deploy
            // will chain a catch-up to HEAD. Mark this trigger superseded (not
            // failed) so it never pollutes failure metrics/alerts.
            $this->log->info('A deploy is already running — this trigger is superseded and will be caught up.');
            $this->deployment->update([
                'status' => Deployment::STATUS_SUPERSEDED,
                'failure_reason' => 'Superseded: another deploy held the lock; the in-progress deploy catches up to HEAD.',
                'finished_at' => now(),
            ]);

            return;
        }

        $succeeded = false;

        try {
            $this->deployment->update([
                'status' => Deployment::STATUS_BUILDING,
                'started_at' => now(),
            ]);

            // Structural skeleton (locked, in order). Everything else — asset
            // builds, migrations, optimize, storage:link, Horizon/Reverb restarts
            // — ships as editable default *hooks* attached to these steps, so the
            // pipeline reads and edits exactly like Envoyer's Deployment Hooks.
            $this->preflight();
            $this->stepFetch();
            $this->stepLinkShared();
            $this->stepComposer();   // default hooks (build/migrate/optimize/storage) run after
            $this->stepHealth();
            $this->stepSwap();
            $this->stepReloadFpm();  // default hooks (horizon/reverb restart) run after
            $this->stepPrune();

            $duration = (int) round((microtime(true) - $this->startedAt) * 1000);
            $this->deployment->update([
                'status' => Deployment::STATUS_DEPLOYED,
                'sha' => $this->sha,
                'release_path' => $this->releasePath,
                'duration_ms' => $duration,
                'warnings' => $this->warnings !== [] ? implode("\n", $this->warnings) : null,
                'finished_at' => now(),
            ]);

            $this->touchServerHeartbeat();

            if ($this->warnings !== []) {
                $this->log->error(sprintf(
                    'DEPLOY completed WITH WARNINGS in %dms — now serving %s. %s',
                    $duration,
                    $this->sha ? substr($this->sha, 0, 7) : '?',
                    implode(' | ', $this->warnings)
                ));
            } else {
                $this->log->info(sprintf(
                    'DEPLOY OK in %dms — now serving %s',
                    $duration,
                    $this->sha ? substr($this->sha, 0, 7) : '?'
                ));
            }

            $succeeded = true;
            $this->notifyFinished();
        } catch (\Throwable $e) {
            $this->fail($e);
            throw $e;
        } finally {
            $lock->release();
        }

        // Catch-up runs OUTSIDE the deploy try, and only after this deploy
        // succeeded — a failing catch-up must never overwrite this deploy's
        // "deployed" row with a failure.
        if ($succeeded) {
            try {
                $this->maybeCatchUp();
            } catch (\Throwable $e) {
                $this->log->warning('Catch-up deploy failed (this deploy is unaffected): '.$e->getMessage());
            }
        }
    }

    protected function addWarning(string $message): void
    {
        $this->warnings[] = $message;
        $this->log->warning($message);
    }

    protected function preflight(): void
    {
        if (! is_file($this->sharedEnv)) {
            throw new RuntimeException(
                "FATAL: shared env not found at {$this->sharedEnv} — create it before the first deploy."
            );
        }

        if (! is_dir($this->repo)) {
            throw new RuntimeException(
                "FATAL: bare mirror not found at {$this->repo} — run php artisan slipstream:provision."
            );
        }

        if (! is_dir($this->releases)) {
            mkdir($this->releases, 0755, true);
        }
    }

    protected function stepFetch(): void
    {
        $step = 'fetch';
        $this->runHooks($step, 'before');

        $deployFrom = $this->deployment->deployFrom();

        // The ref reaches git as its own argv element, so a value starting with
        // `-` would be read as an option rather than a branch/tag. Refuse before
        // the fetch, the same way an unvalidated SHA is refused below.
        $this->assertSafeRef($this->branch);

        $refLabel = $deployFrom === 'tag' ? "tag {$this->branch}" : $this->branch;
        $this->log->stepStart($step, "Fetching {$refLabel}…");

        $shell = $this->shell();

        if ($deployFrom === 'tag') {
            // Dashboard "Deploy from tag": pull the named tag (and its commit object).
            $shell->run([
                'git', '--git-dir='.$this->repo, 'fetch', '--prune', 'origin',
                'tag', $this->branch, '--force',
            ], null, $step);
        } else {
            $shell->run([
                'git', '--git-dir='.$this->repo, 'fetch', '--prune', 'origin',
                '+refs/heads/'.$this->branch.':refs/heads/'.$this->branch,
            ], null, $step);
        }

        // Prefer webhook/manual SHA when set; otherwise resolve branch tip or tag.
        if ($this->deployment->sha) {
            $this->sha = $this->deployment->sha;
        } else {
            $rev = $deployFrom === 'tag' ? 'refs/tags/'.$this->branch : $this->branch;
            [$code, $out] = $shell->run(
                ['git', '--git-dir='.$this->repo, 'rev-parse', $rev],
                null,
                $step
            );
            $this->sha = trim($out);
        }

        // Never let an unvalidated SHA reach a path or a git command — a crafted
        // value (e.g. "../../etc") would escape the releases/ directory.
        $this->assertValidSha($this->sha);

        $short = substr($this->sha, 0, 7);
        $ts = gmdate('Ymd_His');
        $this->releasePath = $this->releases.DIRECTORY_SEPARATOR."{$ts}_{$short}";
        mkdir($this->releasePath, 0755, true);

        // Archive to a temp tar then extract — array-form Process, no shell pipe,
        // so a failing `git archive` surfaces its own non-zero exit instead of
        // being masked by tar's exit code.
        $tar = $this->releasePath.'.tar';
        try {
            $shell->run(
                ['git', '--git-dir='.$this->repo, 'archive', '--format=tar', '-o', $tar, $this->sha],
                null,
                $step
            );
            $shell->run(['tar', '-x', '-f', $tar, '-C', $this->releasePath], null, $step);
        } finally {
            if (is_file($tar)) {
                @unlink($tar);
            }
        }

        file_put_contents($this->releasePath.DIRECTORY_SEPARATOR.'RELEASE_SHA', $this->sha."\n");
        $this->deployment->update([
            'sha' => $this->sha,
            'release_path' => $this->releasePath,
        ]);

        $this->log->stepEnd($step, "Building release {$ts}_{$short}");
        $this->runHooks($step, 'after');
    }

    protected function stepLinkShared(): void
    {
        $step = 'link_shared';
        $this->runHooks($step, 'before');
        $this->log->stepStart($step, 'Linking shared paths…');

        $rel = $this->releasePath;
        $this->symlink($this->sharedEnv, $rel.DIRECTORY_SEPARATOR.'.env');

        if (is_file($this->sharedAuth)) {
            $this->symlink($this->sharedAuth, $rel.DIRECTORY_SEPARATOR.'auth.json');
        }

        // Shared storage: seed skeleton on first deploy.
        if (! is_dir($this->sharedStorage.DIRECTORY_SEPARATOR.'framework')) {
            $this->log->info("Seeding shared storage skeleton at {$this->sharedStorage}", $step);
            if (! is_dir($this->sharedStorage)) {
                mkdir($this->sharedStorage, 0755, true);
            }
            $from = $rel.DIRECTORY_SEPARATOR.'storage';
            if (is_dir($from)) {
                $this->copyTree($from, $this->sharedStorage);
            }
        }

        foreach ([
            'framework/cache/data',
            'framework/sessions',
            'framework/testing',
            'framework/views',
            'app/public',
            'app/private',
            'logs',
        ] as $sub) {
            $dir = $this->sharedStorage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->removePath($rel.DIRECTORY_SEPARATOR.'storage');
        $this->symlink($this->sharedStorage, $rel.DIRECTORY_SEPARATOR.'storage');

        // Custom shared paths (overlay-then-link, like public/images/patient).
        foreach ($this->settings->sharedPaths() as $releaseRelative => $sharedRelative) {
            $sharedAbs = $this->appRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sharedRelative);
            $releaseAbs = $rel.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $releaseRelative);

            if (! is_dir($sharedAbs)) {
                mkdir($sharedAbs, 0755, true);
            }
            if (is_dir($releaseAbs)) {
                $this->copyTree($releaseAbs, $sharedAbs);
            }

            $parent = dirname($releaseAbs);
            if (! is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            $this->removePath($releaseAbs);
            $this->symlink($sharedAbs, $releaseAbs);
        }

        $webGroup = (string) config('slipstream.deploy.web_group', 'www-data');
        if (DIRECTORY_SEPARATOR !== '\\') {
            $shell = $this->shell();
            $shell->run('chgrp -R '.escapeshellarg($webGroup).' '.escapeshellarg($this->sharedStorage).' 2>/dev/null || true', null, $step, throw: false);
            $shell->run('chmod -R g+rwX '.escapeshellarg($this->sharedStorage).' 2>/dev/null || true', null, $step, throw: false);
        }

        $this->log->stepEnd($step);
        $this->runHooks($step, 'after');
    }

    protected function stepComposer(): void
    {
        $step = 'composer';
        $this->runHooks($step, 'before');
        $this->log->stepStart($step, 'composer install…');

        $this->shell($this->releasePath)->run([
            $this->composer, 'install',
            '--no-dev', '--optimize-autoloader', '--no-interaction',
            '--prefer-dist', '--no-progress',
        ], $this->releasePath, $step, timeout: 1800);

        $this->assertSlipstreamInstalled();

        $this->log->stepEnd($step);
        $this->runHooks($step, 'after');
    }

    /**
     * Fail here rather than three steps later with a misleading error.
     *
     * A release built from a commit that predates `composer require slipstream`
     * installs the old lockfile, and the first symptom is the health step
     * reporting that the slipstream artisan namespace does not exist.
     */
    protected function assertSlipstreamInstalled(): void
    {
        $vendorPath = $this->releasePath.DIRECTORY_SEPARATOR.'vendor'
            .DIRECTORY_SEPARATOR.'slipstream'.DIRECTORY_SEPARATOR.'slipstream';

        if (! is_dir($vendorPath)) {
            throw new RuntimeException(
                'Slipstream is missing from this release after composer install — the deployed commit\'s '
                .'composer.lock does not include '.ComposerLockCheck::PACKAGE.'. '
                .ComposerLockCheck::commitFirstMessage()
            );
        }
    }

    protected function stepHealth(): void
    {
        $step = 'health';
        $this->runHooks($step, 'before');
        $this->log->stepStart($step, 'Health check…');

        $this->shell($this->releasePath)->run(
            [$this->php, 'artisan', 'slipstream:healthcheck'],
            $this->releasePath,
            $step
        );

        $this->log->stepEnd($step);
        $this->runHooks($step, 'after');
    }

    protected function stepSwap(): void
    {
        $step = 'swap';
        $this->runHooks($step, 'before');
        $this->log->stepStart($step, "Swapping current -> {$this->releasePath}");

        $tmp = $this->appRoot.DIRECTORY_SEPARATOR.'current.tmp.'.bin2hex(random_bytes(4));
        $this->symlink($this->releasePath, $tmp);

        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows has no mv -T; best-effort for local only.
            if (is_link($this->current) || is_dir($this->current)) {
                @unlink($this->current);
            }
            rename($tmp, $this->current);
        } else {
            $this->shell()->run(
                'mv -T '.escapeshellarg($tmp).' '.escapeshellarg($this->current),
                null,
                $step
            );
        }

        $this->log->stepEnd($step);
        $this->runHooks($step, 'after');
    }

    protected function stepReloadFpm(): void
    {
        $step = 'fpm';

        if (config('slipstream.services.restart_fpm', true) === false) {
            return;
        }

        $service = $this->serverSetting('fpm_service')
            ?: (string) config('slipstream.services.fpm', 'php8.4-fpm');
        $this->runHooks($step, 'before');
        $this->log->stepStart($step, "Reloading {$service}");

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->log->warning('Skipping FPM reload on Windows.', $step);
        } else {
            $sudo = $this->sudoPrefix();
            [$code] = $this->shell()->run(
                $sudo.'systemctl reload '.escapeshellarg($service),
                null,
                $step,
                throw: false
            );

            // The swap already happened, so failing the deploy here can't help —
            // but a failed reload means opcache/FPM keeps serving the OLD code.
            // Surface it loudly instead of hiding behind a green "deployed".
            if ($code !== 0) {
                $this->addWarning(sprintf(
                    "FPM reload failed (exit %d) for service '%s'. The new release is swapped in but FPM may still "
                    .'serve cached old code — check sudoers/NOPASSWD for `systemctl reload`.',
                    $code,
                    $service
                ));
            }
        }

        $this->log->stepEnd($step);
        $this->runHooks($step, 'after');
    }

    protected function stepPrune(): void
    {
        $step = 'prune';
        $this->runHooks($step, 'before');
        $this->log->stepStart($step, "Pruning old releases (keep {$this->keep})…");

        $live = realpath($this->current) ?: '';
        $all = glob($this->releases.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];

        // Preserved failures have their own (smaller) retention and must not
        // consume the keep-N budget for deployable releases.
        [$dirs] = FailedReleases::partition($all);
        usort($dirs, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        foreach (array_slice($dirs, $this->keep) as $old) {
            $real = realpath($old) ?: $old;
            if ($live !== '' && $real === $live) {
                continue;
            }
            $this->log->info("Pruning old release: {$old}", $step);
            $this->removePath($old);
        }

        $keepFailed = (int) config('slipstream.deploy.keep_failed_releases', 1);
        foreach (FailedReleases::prune($this->releases, $keepFailed, fn ($old) => $this->removePath($old)) as $old) {
            $this->log->info("Pruning old failed release: {$old}", $step);
        }

        $this->log->stepEnd($step);
        $this->runHooks($step, 'after');
    }

    protected function maybeCatchUp(): void
    {
        // The lock is already released here; use a fresh handle only to inspect
        // and clear the pending flag a superseded trigger left behind.
        $lock = new DeployLock($this->appRoot);
        if (! $lock->hasPending()) {
            return;
        }

        $lock->clearPending();

        $shell = $this->shell();
        [$code, $out] = $shell->run(
            ['git', '--git-dir='.$this->repo, 'ls-remote', 'origin', '-h', 'refs/heads/'.$this->branch],
            null,
            'catch-up',
            throw: false
        );
        // ls-remote yields "<sha>\t<ref>"; take the first field of the first line.
        $newHead = trim(explode("\t", strtok($out ?: '', "\n") ?: '')[0] ?? '');

        if ($newHead !== '' && $this->sha && $newHead !== $this->sha) {
            $this->log->info("HEAD advanced during deploy — chaining catch-up for {$newHead}");

            $catchUp = Deployment::query()->create([
                'server_name' => Slipstream::serverName(),
                'sha' => $newHead,
                'branch' => $this->branch,
                'trigger' => Deployment::TRIGGER_CATCH_UP,
                'status' => Deployment::STATUS_PENDING,
            ]);

            // Run inline after lock release (same process chain as bash exec).
            $logger = new EventLogger($catchUp);
            $next = new self($catchUp, $this->settings, $logger);
            $next->run();
        }
    }

    protected function fail(\Throwable $e): void
    {
        $this->log->error('DEPLOY FAILED: '.$e->getMessage());

        if ($this->releasePath && is_dir($this->releasePath)) {
            $live = realpath($this->current) ?: '';
            $rel = realpath($this->releasePath) ?: $this->releasePath;
            if ($live === '' || $rel !== $live) {
                $this->disposeOfFailedRelease();
            }
        }

        $duration = (int) round((microtime(true) - $this->startedAt) * 1000);
        $this->deployment->update([
            'status' => Deployment::STATUS_FAILED,
            'failure_reason' => $e->getMessage(),
            'duration_ms' => $duration,
            'finished_at' => now(),
            'sha' => $this->sha ?? $this->deployment->sha,
            'release_path' => $this->releasePath,
        ]);

        $this->notifyFinished();
    }

    /**
     * Keep the half-built release for inspection, or delete it when retention
     * is off. Either way $this->releasePath ends up describing something that
     * exists, so the failed deployment row never points at a deleted directory.
     */
    protected function disposeOfFailedRelease(): void
    {
        $keep = (int) config('slipstream.deploy.keep_failed_releases', 1);

        if ($keep < 1) {
            $this->log->info("Removing half-built release: {$this->releasePath}");
            $this->removePath($this->releasePath);
            $this->releasePath = null;

            return;
        }

        $preserved = $this->releasePath.FailedReleases::SUFFIX;

        if (! @rename($this->releasePath, $preserved)) {
            $this->log->warning("Could not preserve failed release; removing {$this->releasePath}");
            $this->removePath($this->releasePath);
            $this->releasePath = null;

            return;
        }

        $this->releasePath = $preserved;
        $this->log->info("Kept the failed release for inspection: {$preserved}");

        // Prune here as well as in stepPrune: when every deploy fails, a
        // successful prune step never runs and these would accumulate forever.
        foreach (FailedReleases::prune($this->releases, $keep, fn ($old) => $this->removePath($old)) as $old) {
            $this->log->info("Pruning old failed release: {$old}");
        }
    }

    protected function touchServerHeartbeat(): void
    {
        app(Heartbeat::class)->pulse($this->sha);
    }

    protected function notifyFinished(): void
    {
        try {
            $this->deployment->refresh();
            app(DeployNotifier::class)->deploymentFinished($this->deployment);
        } catch (\Throwable $e) {
            $this->log->warning('Notification failed: '.$e->getMessage());
        }
    }

    protected function runHooks(string $step, string $position): void
    {
        $hooks = DeploymentHook::query()
            ->where('step', $step)
            ->where('position', $position)
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $server = Slipstream::serverName();
        $cwd = $this->releasePath ?? $this->appRoot;

        $skipIds = $this->deployment->skippedHookIds();

        foreach ($hooks as $hook) {
            if (! $hook->appliesToServer($server)) {
                continue;
            }

            if (in_array((int) $hook->id, $skipIds, true)) {
                $this->log->info("Skipping hook \"{$hook->name}\" — deselected for this deploy.", $step);

                continue;
            }

            if ($hook->fleet_lock) {
                $this->runFleetLockedHook($hook, $cwd, $step, $position);

                continue;
            }

            $this->log->info("Hook [{$position} {$step}] {$hook->name}", $step);
            $this->shell($cwd)->run($this->renderHookScript($hook->script), $cwd, $step);
        }
    }

    /**
     * A fleet-locked hook (e.g. the default "Run migrations") runs on exactly one
     * server per commit: the first eligible node to win a fleet-wide cache lock
     * keyed on the SHA. The lock MUST live on a store shared by every node —
     * file/array give each server its own lock and all of them would run it.
     */
    protected function runFleetLockedHook(DeploymentHook $hook, string $cwd, string $step, string $position): void
    {
        // A server flagged not to run migrations opts out of fleet-locked hooks.
        $server = Server::query()->where('server_name', Slipstream::serverName())->first();
        if ($server && ! $server->run_migrations) {
            $this->log->info("Skipping fleet-locked hook \"{$hook->name}\" — this server isn't fleet-task eligible.", $step);

            return;
        }

        $storeName = config('slipstream.deploy.migration_lock_store');
        $cache = $storeName ? Cache::store($storeName) : Cache::store();
        $this->warnIfUnsharedLockStore($storeName, $step);

        // Lock TTL is deliberately longer than any single hook so a slow task
        // never outlives its own lock and lets a peer double-run.
        $ttl = (int) config('slipstream.deploy.migration_lock_ttl', 900);
        $lockKey = 'slipstream:hook:'.$hook->id.':'.($this->sha ?? 'unknown');

        $lock = $cache->lock($lockKey, $ttl);
        if ($lock->get()) {
            try {
                $this->log->info("Hook [{$position} {$step}] {$hook->name} (fleet lock — this server runs it)", $step);
                $this->shell($cwd)->run($this->renderHookScript($hook->script), $cwd, $step);
            } finally {
                optional($lock)->release();
            }
        } else {
            $this->log->info("Skipping fleet-locked hook \"{$hook->name}\" — another server holds the lock.", $step);
        }
    }

    /**
     * Substitute Envoyer-style placeholders in a hook script. Binaries resolve to
     * the configured php/npm/composer so a default hook works even when they're
     * not on PATH.
     */
    protected function renderHookScript(string $script): string
    {
        $release = $this->releasePath ?? $this->appRoot;

        return strtr($script, [
            '{{ release }}' => $release,
            '{{release}}' => $release,
            '{{ sha }}' => (string) $this->sha,
            '{{sha}}' => (string) $this->sha,
            '{{ branch }}' => $this->branch,
            '{{branch}}' => $this->branch,
            '{{ app_root }}' => $this->appRoot,
            '{{app_root}}' => $this->appRoot,
            '{{ php }}' => $this->php,
            '{{php}}' => $this->php,
            '{{ npm }}' => $this->npm,
            '{{npm}}' => $this->npm,
            '{{ composer }}' => $this->composer,
            '{{composer}}' => $this->composer,
        ]);
    }

    protected function shell(?string $cwd = null): ShellRunner
    {
        return new ShellRunner($this->log, $cwd);
    }

    protected function serverSetting(string $column): ?string
    {
        static $row = false;
        if ($row === false) {
            $row = Server::query()->where('server_name', Slipstream::serverName())->first();
        }
        if (! $row) {
            return null;
        }
        $value = $row->{$column} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function sudoPrefix(): string
    {
        return Sudo::prefix();
    }

    protected function warnIfUnsharedLockStore(?string $storeName, string $step): void
    {
        $resolved = $storeName ?: (string) config('cache.default');
        $driver = config("cache.stores.{$resolved}.driver");

        if (! in_array($driver, ['file', 'array', 'null', null], true)) {
            return;
        }

        // Only a real problem when more than one server participates.
        try {
            $fleet = Server::query()->where('enabled', true)->count();
        } catch (\Throwable) {
            $fleet = 1;
        }

        if ($fleet > 1) {
            $this->addWarning(sprintf(
                'Migration lock store "%s" uses the "%s" driver, which is NOT shared across servers — '
                .'multiple nodes may run migrations for one commit. Set slipstream.deploy.migration_lock_store '
                .'to a shared store (redis/database/memcached).',
                $resolved,
                (string) $driver
            ));
        }
    }

    public static function isValidSha(?string $sha): bool
    {
        return is_string($sha) && (bool) preg_match('/^[0-9a-f]{7,64}$/i', $sha);
    }

    protected function assertValidSha(?string $sha): void
    {
        if (! self::isValidSha($sha)) {
            throw new RuntimeException(
                'Refusing to deploy — resolved commit SHA is not a valid hex object id: '.var_export($sha, true)
            );
        }
    }

    protected function assertSafeRef(?string $ref): void
    {
        if (! GitRef::isSafeArgument($ref)) {
            throw new RuntimeException(
                'Refusing to deploy — branch/tag is not a usable git ref: '.var_export($ref, true)
            );
        }
    }

    protected function symlink(string $target, string $link): void
    {
        if (is_link($link) || file_exists($link)) {
            if (is_link($link) || is_file($link)) {
                @unlink($link);
            } else {
                $this->removePath($link);
            }
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            // Junction/symlink needs elevation on Windows; use link when possible.
            if (! @symlink($target, $link)) {
                throw new RuntimeException("Failed to symlink {$link} -> {$target}");
            }

            return;
        }

        // ln -sfn equivalent
        if (! symlink($target, $link) && ! (is_link($link) && readlink($link) === $target)) {
            // Race: create temp then rename
            $tmp = $link.'.tmp.'.bin2hex(random_bytes(4));
            if (! symlink($target, $tmp)) {
                throw new RuntimeException("Failed to symlink {$link} -> {$target}");
            }
            rename($tmp, $link);
        }
    }

    protected function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($path);
    }

    protected function copyTree(string $from, string $to): void
    {
        if (! is_dir($to)) {
            mkdir($to, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $to.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                $dir = dirname($target);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                // Do not overwrite existing files in shared (uploads persist).
                if (! file_exists($target)) {
                    copy($item->getPathname(), $target);
                }
            }
        }
    }
}
