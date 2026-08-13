<?php

namespace Slipstream\Deploy;

use RuntimeException;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Models\Deployment;
use Slipstream\Notifications\DeployNotifier;
use Slipstream\Slipstream;
use Slipstream\Support\Sudo;

/**
 * Port of deploy/rollback.sh — code-only rollback; does not un-migrate.
 */
class Rollbacker
{
    protected string $appRoot;

    protected string $releases;

    protected string $current;

    protected string $php;

    protected float $startedAt;

    /** @var list<string> */
    protected array $warnings = [];

    public function __construct(
        protected Deployment $deployment,
        protected EventLogger $log,
    ) {
        $this->appRoot = Slipstream::appRoot();
        $this->releases = $this->appRoot.DIRECTORY_SEPARATOR.'releases';
        $this->current = $this->appRoot.DIRECTORY_SEPARATOR.'current';
        $this->php = (string) config('slipstream.deploy.php_binary', PHP_BINARY);
        $this->startedAt = microtime(true);
    }

    public function run(): void
    {
        // Share the deploy lock so a rollback and an in-flight deploy can't both
        // rewrite `current` and race on the swap.
        $lock = new DeployLock($this->appRoot);
        if (! $lock->acquire()) {
            $this->log->error('ROLLBACK ABORTED: a deploy or rollback is already in progress.');
            $this->deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => 'A deploy or rollback is already running; try again once it finishes.',
                'finished_at' => now(),
            ]);
            $lock->clearPending();

            return;
        }

        $this->deployment->update([
            'status' => Deployment::STATUS_BUILDING,
            'started_at' => now(),
            'trigger' => Deployment::TRIGGER_ROLLBACK,
        ]);

        $this->log->warning(
            'CAVEAT: rollback repoints CODE only. It cannot un-migrate the database. '
            .'Migrations must be expand→contract.'
        );

        try {
            $prev = $this->releaseOlderThanLive();

            if ($prev === null) {
                throw new RuntimeException('No release older than the current one to roll back to.');
            }

            $live = realpath($this->current) ?: '';
            $this->log->info("Rolling back: {$live} -> {$prev}");

            $tmp = $this->appRoot.DIRECTORY_SEPARATOR.'current.tmp.'.bin2hex(random_bytes(4));
            if (is_link($tmp) || file_exists($tmp)) {
                @unlink($tmp);
            }
            if (! symlink($prev, $tmp)) {
                throw new RuntimeException("Failed to create temp symlink to {$prev}");
            }

            if (DIRECTORY_SEPARATOR === '\\') {
                if (is_link($this->current) || is_dir($this->current)) {
                    @unlink($this->current);
                }
                rename($tmp, $this->current);
            } else {
                $shell = new ShellRunner($this->log);
                $shell->run('mv -T '.escapeshellarg($tmp).' '.escapeshellarg($this->current));
            }

            $shaFile = $prev.DIRECTORY_SEPARATOR.'RELEASE_SHA';
            $sha = is_file($shaFile) ? trim((string) file_get_contents($shaFile)) : null;

            $this->reloadServices();

            $duration = (int) round((microtime(true) - $this->startedAt) * 1000);
            $this->deployment->update([
                'status' => Deployment::STATUS_ROLLED_BACK,
                'sha' => $sha,
                'release_path' => $prev,
                'duration_ms' => $duration,
                'warnings' => $this->warnings !== [] ? implode("\n", $this->warnings) : null,
                'finished_at' => now(),
            ]);

            $this->log->info('Rollback complete — now serving '.($sha ? substr($sha, 0, 7) : 'unknown'));

            app(Heartbeat::class)->pulse($sha);
            $this->notifyFinished();
        } catch (\Throwable $e) {
            $this->log->error('ROLLBACK FAILED: '.$e->getMessage());
            $this->deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
                'finished_at' => now(),
            ]);
            $this->notifyFinished();
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * The release immediately older than the currently-live one (by mtime), so
     * repeated rollbacks step further back through history and can never select
     * a NEWER release than what is live (which would "roll forward" onto the bad
     * build a second rollback is trying to escape).
     */
    protected function releaseOlderThanLive(): ?string
    {
        $live = realpath($this->current) ?: '';
        $dirs = glob($this->releases.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        usort($dirs, fn ($a, $b) => filemtime($b) <=> filemtime($a)); // newest → oldest

        return self::pickOlderThanLive($dirs, $live);
    }

    /**
     * Given releases sorted newest→oldest and the realpath of the live release,
     * return the release immediately older than live (or the newest non-live
     * release if live isn't in the list). Pure so it's unit-testable without
     * symlinks/git.
     *
     * @param  list<string>  $dirsNewestFirst
     */
    public static function pickOlderThanLive(array $dirsNewestFirst, string $live): ?string
    {
        $liveIndex = null;
        foreach ($dirsNewestFirst as $i => $dir) {
            if ($live !== '' && (realpath($dir) ?: $dir) === $live) {
                $liveIndex = $i;
                break;
            }
        }

        if ($liveIndex !== null) {
            // The next entry after live (older) — never a newer release.
            return $dirsNewestFirst[$liveIndex + 1] ?? null;
        }

        // Live not among the releases (unknown state) — newest non-live.
        foreach ($dirsNewestFirst as $dir) {
            if ($live === '' || (realpath($dir) ?: $dir) !== $live) {
                return $dir;
            }
        }

        return null;
    }

    protected function notifyFinished(): void
    {
        try {
            $this->deployment->refresh();
            app(DeployNotifier::class)->deploymentFinished($this->deployment);
        } catch (\Throwable) {
            // never break rollback for notification errors
        }
    }

    protected function reloadServices(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->log->warning('Skipping FPM/Horizon/Reverb restart on Windows.');

            return;
        }

        $shell = new ShellRunner($this->log);
        $sudo = Sudo::prefix();

        if (config('slipstream.services.restart_fpm', true) !== false) {
            $fpm = (string) config('slipstream.services.fpm', 'php8.4-fpm');
            [$code] = $shell->run($sudo.'systemctl reload '.escapeshellarg($fpm), throw: false);
            if ($code !== 0) {
                $this->addWarning("FPM reload failed for '{$fpm}' — rolled-back code may not be served until FPM reloads.");
            }
        }

        $artisan = $this->current.DIRECTORY_SEPARATOR.'artisan';

        if ($this->serviceEnabled('restart_horizon', 'laravel/horizon') && is_file($artisan)) {
            $horizon = (string) config('slipstream.services.horizon', 'horizon');
            [$code] = $shell->run([$this->php, $artisan, 'horizon:terminate'], dirname($artisan), throw: false);
            if ($code !== 0) {
                [$sc] = $shell->run($sudo.'supervisorctl restart '.escapeshellarg($horizon), throw: false);
                if ($sc !== 0) {
                    $this->addWarning("Horizon restart failed during rollback (program '{$horizon}').");
                }
            }
        }

        if ($this->serviceEnabled('restart_reverb', 'laravel/reverb') && is_file($artisan)) {
            $reverb = (string) config('slipstream.services.reverb', 'reverb');
            [$code] = $shell->run([$this->php, $artisan, 'reverb:restart'], dirname($artisan), throw: false);
            if ($code !== 0) {
                [$sc] = $shell->run($sudo.'supervisorctl restart '.escapeshellarg($reverb), throw: false);
                if ($sc !== 0) {
                    $this->addWarning("Reverb restart failed during rollback (program '{$reverb}').");
                }
            }
        }
    }

    protected function serviceEnabled(string $configKey, string $composerPackage): bool
    {
        $mode = config("slipstream.services.{$configKey}");
        if ($mode === false) {
            return false;
        }

        $installed = is_dir(
            $this->current.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $composerPackage)
        );

        return $mode === true || $installed;
    }

    protected function addWarning(string $message): void
    {
        $this->warnings[] = $message;
        $this->log->warning($message);
    }
}
