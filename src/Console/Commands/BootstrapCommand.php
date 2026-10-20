<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Database\Tables;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Deploy\DeploymentRunner;
use Slipstream\Deploy\HealthGate;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Fleet\LastGood;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Release\ArtifactSource;
use Slipstream\Release\DeployRef;
use Slipstream\Release\ReleaseHistory;
use Slipstream\Release\ReleaseRef;
use Slipstream\Release\ReleaseSource;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

/**
 * Bring a fresh instance up, from a launch script: Slipstream's tables, this
 * server in the registry, the git mirror, the deploy ref deployed (blocking)
 * and the health gate passed on what is live. Exits non-zero unless the node
 * can serve, so the instance only joins the load balancer once it can.
 *
 * Idempotent: on a node already serving the ref's commit it only re-checks,
 * and every step it repeats is safe to repeat.
 *
 * The artisan that runs this has to come from somewhere before the first
 * release exists — stubs/slipstream-bootstrap.sh makes a throwaway checkout
 * of the deploy ref with its dependencies, runs this from it, and removes it.
 */
class BootstrapCommand extends Command
{
    protected $signature = 'slipstream:bootstrap
                            {--repo= : Repository to mirror when this node has none (default: SLIPSTREAM_REPOSITORY; unused in artifact mode)}
                            {--no-fallback : Fail rather than deploy the fleet\'s last good commit when the deploy ref\'s own commit fails}';

    protected $description = 'Bring a fresh instance up: tables, registry, mirror, the deploy ref deployed and health-checked; non-zero unless it can serve';

    public function handle(
        Tables $tables,
        SettingsRepository $settings,
        ReleaseSource $source,
        DeploymentRunner $runner,
        HealthGate $health,
        LastGood $lastGood,
        Heartbeat $heartbeat,
    ): int {
        $root = Slipstream::appRoot();
        $self = Slipstream::serverName();
        $this->components->info("Bootstrapping {$self} at {$root}");

        if (! is_file(Slipstream::sharedEnvPath())) {
            return $this->failWith('No shared .env at '.Slipstream::sharedEnvPath().'. The launch script puts it there before bootstrap runs.');
        }

        $configured = Slipstream::databaseConnection();
        if ($configured !== null && ! $tables->defined($configured)) {
            return $this->failWith("SLIPSTREAM_DB_CONNECTION names \"{$configured}\", which config/database.php does not define.");
        }

        $connection = $tables->activeConnection();
        $tables->ensureSqliteFile($connection);
        $tables->ensureCurrent($connection);
        $this->components->twoColumnDetail('Tables', "current on {$connection}");

        $this->register($self);
        $this->components->twoColumnDetail('Hooks', DefaultHooks::seedFor(base_path()));

        // Artifact mode: no mirror, no git. The release comes from the store.
        if (! Slipstream::artifactMode() && ! is_dir($root.DIRECTORY_SEPARATOR.'repo.git') && ($code = $this->mirror($settings)) !== self::SUCCESS) {
            return $code;
        }

        try {
            $ref = DeployRef::fromConfig();
            $release = $source->latest($ref);
        } catch (\Throwable $e) {
            return $this->failWith('Cannot resolve the deploy ref: '.$e->getMessage());
        }
        $this->components->twoColumnDetail('Deploy ref', $ref->describe().' → '.$release->name.' at '.$release->short());

        // In artifact mode, the signed pointer that named the release: the
        // deploy fetches the manifest it names, and its previous is the one
        // release a fresh node may fall back to.
        $pointer = $source instanceof ArtifactSource ? $source->pointer($ref) : null;

        // Only what current serves is live. The checkout this runs from is a
        // throwaway clone of the deploy ref, so its HEAD is usually the very
        // commit about to be deployed, and says nothing about this node.
        $running = $heartbeat->liveSha($root);
        $fellBack = null;

        if ($running === $release->sha) {
            $this->components->twoColumnDetail('Release', 'already serving '.$release->short());
        } elseif ($pointer !== null) {
            // Deploy what the pointer names. If the pointer moves on meanwhile
            // (the deploy ends superseded, or fails and the pointer has moved),
            // follow it to the new head — a few times at most — rather than
            // fall back to a previous that is stale too.
            $outcome = null;
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $outcome = $this->deploy($runner, $release, $ref, true, ['sequence' => $pointer->sequence, 'manifest_sha256' => $pointer->manifestSha256])->status;
                if ($outcome === Deployment::STATUS_DEPLOYED) {
                    break;
                }

                $fresh = $this->freshPointer($source, $ref);
                if ($fresh === null || $fresh->sha === $release->sha) {
                    $pointer = $fresh ?? $pointer;

                    break;
                }
                $pointer = $fresh;
                $release = new ReleaseRef($fresh->sha, $release->name, $ref->isTag());
                $this->components->warn('The pointer moved to '.$release->short().' while bootstrap deployed; deploying that instead.');
            }

            if ($outcome !== Deployment::STATUS_DEPLOYED) {
                if ($this->option('no-fallback') || $pointer->previous === null || $pointer->previous['sha'] === $release->sha) {
                    Activity::log('bootstrap.failed', ['sha' => $release->sha, 'ref' => $release->ref()]);

                    return $this->failWith('The deploy of '.$release->short().' failed'
                        .($this->option('no-fallback') ? '.' : ', and the pointer names no previous release to fall back to.'));
                }

                $fallback = new ReleaseRef($pointer->previous['sha'], $release->name, $ref->isTag());
                $this->components->warn('Falling back to the pointer\'s previous release '.$fallback->short().', which the signed pointer vouches for.');
                Activity::log('bootstrap.fell_back', ['failed_sha' => $release->sha, 'sha' => $fallback->sha, 'ref' => $release->ref(), 'from' => 'pointer.previous']);

                if ($this->deploy($runner, $fallback, $ref, false, ['sequence' => $pointer->sequence, 'manifest_sha256' => $pointer->previous['manifest_sha256']])->status !== Deployment::STATUS_DEPLOYED) {
                    Activity::log('bootstrap.failed', ['sha' => $fallback->sha, 'ref' => $release->ref(), 'fallback' => true]);

                    return $this->failWith('The fallback deploy of '.$fallback->short().' failed too.');
                }

                $fellBack = $fallback;
            }
        } elseif ($this->deploy($runner, $release, $ref, true)->status !== Deployment::STATUS_DEPLOYED) {
            $good = $this->option('no-fallback') ? null : $lastGood->get($ref);
            $refused = null;

            if ($good !== null && $good['sha'] !== $release->sha
                && ($refused = $this->refuseFallback($source, $ref, $release, $good['sha'])) !== null) {
                $this->components->warn('Not falling back to the fleet\'s last good commit '.substr($good['sha'], 0, 7).": {$refused}.");
                Activity::log('bootstrap.fallback_refused', ['sha' => $good['sha'], 'ref' => $release->ref(), 'reason' => $refused]);
                $good = null;
            }

            if ($good === null || $good['sha'] === $release->sha) {
                Activity::log('bootstrap.failed', ['sha' => $release->sha, 'ref' => $release->ref()]);

                return $this->failWith('The deploy of '.$release->short().' failed'.match (true) {
                    (bool) $this->option('no-fallback') => '.',
                    $refused !== null => ', and the deploy ref no longer contains the fleet\'s last good commit.',
                    default => ', and the fleet has no other good commit to fall back to.',
                });
            }

            $this->components->warn(sprintf(
                'Falling back to the fleet\'s last good commit %s (%s, deployed by %s at %s).',
                substr($good['sha'], 0, 7), $good['name'], $good['node'], $good['at']
            ));
            Activity::log('bootstrap.fell_back', ['failed_sha' => $release->sha, 'sha' => $good['sha'], 'ref' => $release->ref()]);

            $fallback = new ReleaseRef($good['sha'], $good['name'], $ref->isTag());
            if ($this->deploy($runner, $fallback, $ref, false)->status !== Deployment::STATUS_DEPLOYED) {
                Activity::log('bootstrap.failed', ['sha' => $good['sha'], 'ref' => $release->ref(), 'fallback' => true]);

                return $this->failWith('The fallback deploy of '.$fallback->short().' failed too.');
            }

            $fellBack = $fallback;
        }

        $live = realpath($root.DIRECTORY_SEPARATOR.'current') ?: $root.DIRECTORY_SEPARATOR.'current';
        $check = $health->check($live);
        if (! $check['ok']) {
            Activity::log('bootstrap.failed', ['reason' => 'health', 'sha' => $heartbeat->liveSha($root)]);
            $this->line($check['output']);

            return $this->failWith('The health check failed on the live release.');
        }
        $this->components->twoColumnDetail('Health', 'passed');

        $serving = $fellBack ?? $release;
        Activity::log('bootstrap.finished', ['sha' => $serving->sha, 'ref' => $release->ref(), 'fell_back' => $fellBack !== null]);

        if ($fellBack !== null) {
            $this->components->warn('Serving the last good commit '.$fellBack->short().', not the ref\'s '.$release->short().'. The next check retries the ref, with back-off.');
        }

        $this->components->info('Ready: serving '.$serving->short().'.');

        return self::SUCCESS;
    }

    /**
     * Register this node, unheld. A pause or pin already on the row belongs
     * to whatever held the name before (an instance that had the same
     * hostname, or this node before it was rebuilt). Bootstrap deploys the
     * ref regardless, so a hold left in place would only stop the node
     * following it afterwards.
     */
    protected function register(string $self): void
    {
        $server = Server::query()->firstOrNew(['server_name' => $self]);
        $held = $server->exists && ($server->paused || $server->pinned_sha !== null);
        $pinned = $server->pinned_sha;

        $server->fill([
            'hostname' => gethostname() ?: null,
            'php_version' => PHP_VERSION,
            'fpm_service' => config('slipstream.services.fpm'),
            'horizon_program' => config('slipstream.services.horizon'),
            'reverb_program' => config('slipstream.services.reverb'),
            'last_heartbeat_at' => now(),
            'paused' => false,
            'pinned_sha' => null,
            'pinned_reason' => null,
            'pinned_at' => null,
        ])->save();

        $this->components->twoColumnDetail('Server', $self.($held ? ' (pause and pin cleared)' : ''));

        if ($held) {
            Activity::log('bootstrap.hold_cleared', ['server' => $self, 'pinned_sha' => $pinned]);
        }
    }

    /**
     * Why the fleet's last good commit may not be deployed here, or null when
     * it may. The deploy ref must still contain it: the shared store only
     * suggests a commit, and the protected ref is what vouches for one. A
     * rolled-back release, or a commit nobody reviewed, never comes back
     * through this door.
     */
    protected function refuseFallback(ReleaseSource $source, DeployRef $ref, ReleaseRef $head, string $sha): ?string
    {
        if (! $source instanceof ReleaseHistory) {
            return 'the release source cannot confirm the deploy ref contains it';
        }

        try {
            if ($source->contains($ref, $head, $sha)) {
                return null;
            }
        } catch (\Throwable $e) {
            return 'the deploy ref could not be checked: '.$e->getMessage();
        }

        return match ($ref->kind) {
            DeployRef::BRANCH => "it is not in the history of branch {$ref->name}",
            DeployRef::TAG => "tag {$ref->name} points at {$head->short()}, not at it",
            default => "no tag matching {$ref->name} points at it now",
        };
    }

    /**
     * Run one deployment of a commit to completion. The ref's own commit is
     * marked as the ref, so it counts towards the fleet's last good commit;
     * a fallback is not.
     */
    /**
     * @param  array{sequence: int, manifest_sha256: string}|null  $pointer  artifact mode: the pointer that vouches for this release, and the manifest it names
     */
    protected function deploy(DeploymentRunner $runner, ReleaseRef $release, DeployRef $ref, bool $isRef, ?array $pointer = null): Deployment
    {
        $deployment = Deployment::query()->create([
            'server_name' => Slipstream::serverName(),
            'sha' => $release->sha,
            'branch' => $release->name,
            'trigger' => Deployment::TRIGGER_BOOTSTRAP,
            'status' => Deployment::STATUS_PENDING,
            'meta' => array_filter([
                'deploy_from' => $release->isTag ? 'tag' : null,
                'poll_ref' => $isRef ? $ref->key() : null,
                'pointer' => $pointer,
            ]),
        ]);

        $this->components->twoColumnDetail('Deploying', $release->short().' ('.$release->name.') as #'.$deployment->id);
        $deployment = $runner->run($deployment);

        if ($deployment->status !== Deployment::STATUS_DEPLOYED) {
            $this->components->error("Deploy #{$deployment->id} ended {$deployment->status}: ".($deployment->failure_reason ?: 'no reason recorded'));
            $this->line("  Log: php artisan slipstream:deployment {$deployment->id}");

            return $deployment;
        }

        $this->components->twoColumnDetail('Deployed', $release->short());

        return $deployment;
    }

    /**
     * The pointer as the store holds it now, or null when it cannot be had.
     */
    protected function freshPointer(ReleaseSource $source, DeployRef $ref): ?\Slipstream\Release\Core\Pointer
    {
        if (! $source instanceof ArtifactSource) {
            return null;
        }

        try {
            return $source->pointer($ref, fresh: true);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function mirror(SettingsRepository $settings): int
    {
        $repo = $this->option('repo') ?: $settings->repoUrl();

        if (! $repo) {
            return $this->failWith('This node has no git mirror and no repository to make one from. Set SLIPSTREAM_REPOSITORY or pass --repo.');
        }

        $code = $this->call('slipstream:provision', ['--repo' => $repo, '--skip-key' => true, '--no-adopt' => true]);

        if ($code !== self::SUCCESS || ! is_dir(Slipstream::appRoot().DIRECTORY_SEPARATOR.'repo.git')) {
            return $this->failWith("Could not mirror {$repo}. The deploy key must be readable by this user.");
        }

        return self::SUCCESS;
    }

    protected function failWith(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
