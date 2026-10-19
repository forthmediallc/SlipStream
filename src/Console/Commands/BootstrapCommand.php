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
use Slipstream\Release\DeployRef;
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
 * with its dependencies, runs this from it, and removes it.
 */
class BootstrapCommand extends Command
{
    protected $signature = 'slipstream:bootstrap
                            {--repo= : Repository to mirror when this node has none (default: SLIPSTREAM_REPOSITORY)}
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

        Server::query()->updateOrCreate(['server_name' => $self], [
            'hostname' => gethostname() ?: null,
            'php_version' => PHP_VERSION,
            'fpm_service' => config('slipstream.services.fpm'),
            'horizon_program' => config('slipstream.services.horizon'),
            'reverb_program' => config('slipstream.services.reverb'),
            'last_heartbeat_at' => now(),
        ]);
        $this->components->twoColumnDetail('Server', $self);
        $this->components->twoColumnDetail('Hooks', DefaultHooks::seedFor(base_path()));

        if (! is_dir($root.DIRECTORY_SEPARATOR.'repo.git') && ($code = $this->mirror($settings)) !== self::SUCCESS) {
            return $code;
        }

        try {
            $ref = DeployRef::fromConfig();
            $release = $source->latest($ref);
        } catch (\Throwable $e) {
            return $this->failWith('Cannot resolve the deploy ref: '.$e->getMessage());
        }
        $this->components->twoColumnDetail('Deploy ref', $ref->describe().' → '.$release->name.' at '.$release->short());

        $running = $heartbeat->readCurrentSha($root);
        $fellBack = null;

        if (is_string($running) && strtolower($running) === $release->sha) {
            $this->components->twoColumnDetail('Release', 'already serving '.$release->short());
        } elseif (! $this->deploy($runner, $release, $ref, true)) {
            $good = $this->option('no-fallback') ? null : $lastGood->get($ref);

            if ($good === null || $good['sha'] === $release->sha) {
                Activity::log('bootstrap.failed', ['sha' => $release->sha, 'ref' => $release->ref()]);

                return $this->failWith('The deploy of '.$release->short().' failed'.($this->option('no-fallback') ? '.' : ', and the fleet has no other good commit to fall back to.'));
            }

            $this->components->warn(sprintf(
                'Falling back to the fleet\'s last good commit %s (%s, deployed by %s at %s).',
                substr($good['sha'], 0, 7), $good['name'], $good['node'], $good['at']
            ));
            Activity::log('bootstrap.fell_back', ['failed_sha' => $release->sha, 'sha' => $good['sha'], 'ref' => $release->ref()]);

            $fallback = new ReleaseRef($good['sha'], $good['name'], $ref->isTag());
            if (! $this->deploy($runner, $fallback, $ref, false)) {
                Activity::log('bootstrap.failed', ['sha' => $good['sha'], 'ref' => $release->ref(), 'fallback' => true]);

                return $this->failWith('The fallback deploy of '.$fallback->short().' failed too.');
            }

            $fellBack = $fallback;
        }

        $live = realpath($root.DIRECTORY_SEPARATOR.'current') ?: $root.DIRECTORY_SEPARATOR.'current';
        $check = $health->check($live);
        if (! $check['ok']) {
            Activity::log('bootstrap.failed', ['reason' => 'health', 'sha' => $heartbeat->readCurrentSha($root)]);
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
     * Run one deployment of a commit to completion. The ref's own commit is
     * marked as the ref, so it counts towards the fleet's last good commit;
     * a fallback is not.
     */
    protected function deploy(DeploymentRunner $runner, ReleaseRef $release, DeployRef $ref, bool $isRef): bool
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
            ]),
        ]);

        $this->components->twoColumnDetail('Deploying', $release->short().' ('.$release->name.') as #'.$deployment->id);
        $deployment = $runner->run($deployment);

        if ($deployment->status !== Deployment::STATUS_DEPLOYED) {
            $this->components->error("Deploy #{$deployment->id} ended {$deployment->status}: ".($deployment->failure_reason ?: 'no reason recorded'));
            $this->line("  Log: php artisan slipstream:deployment {$deployment->id}");

            return false;
        }

        $this->components->twoColumnDetail('Deployed', $release->short());

        return true;
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
