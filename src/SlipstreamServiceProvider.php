<?php

namespace Slipstream;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Slipstream\Console\Commands\BootstrapCommand;
use Slipstream\Console\Commands\DatabaseCommand;
use Slipstream\Console\Commands\DeliveriesCommand;
use Slipstream\Console\Commands\DeployCommand;
use Slipstream\Console\Commands\DeploymentCommand;
use Slipstream\Console\Commands\DeploymentsCommand;
use Slipstream\Console\Commands\DeployNudgeCommand;
use Slipstream\Console\Commands\DoctorCommand;
use Slipstream\Console\Commands\EnvApplyCommand;
use Slipstream\Console\Commands\EnvNudgeCommand;
use Slipstream\Console\Commands\HealthcheckCommand;
use Slipstream\Console\Commands\HeartbeatCommand;
use Slipstream\Console\Commands\HooksCommand;
use Slipstream\Console\Commands\InstallCommand;
use Slipstream\Console\Commands\PipelinesCommand;
use Slipstream\Console\Commands\ProvisionCommand;
use Slipstream\Console\Commands\PruneCommand;
use Slipstream\Console\Commands\PublishCommand;
use Slipstream\Console\Commands\RelayCommand;
use Slipstream\Console\Commands\RollbackCommand;
use Slipstream\Console\Commands\ServersCommand;
use Slipstream\Console\Commands\SettingsCommand;
use Slipstream\Console\Commands\StatusCommand;
use Slipstream\Console\Commands\WebhookSecretCommand;
use Slipstream\Http\Middleware\AuthorizeSlipstream;
use Slipstream\Livewire\DeployHooksPage;
use Slipstream\Livewire\DeploymentShow;
use Slipstream\Livewire\DeploymentsIndex;
use Slipstream\Livewire\EnvironmentPage;
use Slipstream\Livewire\PipelinesPage;
use Slipstream\Livewire\ProjectChrome;
use Slipstream\Livewire\ServersPage;
use Slipstream\Livewire\SettingsPage;
use Slipstream\Livewire\WebhookPage;
use Slipstream\Release\GitRefSource;
use Slipstream\Release\ReleaseSource;
use Slipstream\Settings\SettingsRepository;

class SlipstreamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/slipstream.php', 'slipstream');

        $this->app->singleton(Slipstream::class, fn () => new Slipstream);
        $this->app->singleton(SettingsRepository::class);

        // Where poll mode and bootstrap learn what to deploy. Git today; an
        // artifact source (S3 or SSM pointer to a CI-built tarball) slots in here.
        $this->app->bindIf(ReleaseSource::class, GitRefSource::class);
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMigrations();
        $this->registerCommands();
        $this->excludeHooksFromCsrf();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerLivewire();
        $this->registerSchedule();
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/slipstream.php' => config_path('slipstream.php'),
        ], 'slipstream-config');

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/slipstream'),
        ], 'slipstream-assets');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'slipstream-migrations');
    }

    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            PublishCommand::class,
            DeployCommand::class,
            RollbackCommand::class,
            HealthcheckCommand::class,
            ProvisionCommand::class,
            DoctorCommand::class,
            HeartbeatCommand::class,
            PruneCommand::class,
            RelayCommand::class,
            EnvApplyCommand::class,
            EnvNudgeCommand::class,
            DeployNudgeCommand::class,
            WebhookSecretCommand::class,
            // The dashboard pages as commands, for PCI nodes and for scripts.
            StatusCommand::class,
            DeploymentsCommand::class,
            DeploymentCommand::class,
            DeliveriesCommand::class,
            HooksCommand::class,
            ServersCommand::class,
            PipelinesCommand::class,
            SettingsCommand::class,
            DatabaseCommand::class,
            BootstrapCommand::class,
        ]);
    }

    /**
     * Exclude the hook endpoints from CSRF at the framework level, in addition
     * to the per-route exclusion in routes/web.php.
     *
     * The route-level `withoutMiddleware([ValidateCsrfToken::class])` only
     * strips a middleware registered under that exact class name. A host app
     * that registers its own subclass — the old App\Http\Middleware\
     * VerifyCsrfToken, or a global/priority registration — keeps CSRF in the
     * stack, and GitHub deliveries come back 419. The static exclusion list
     * lives on the shared base class, so it covers those subclasses too.
     *
     * A CSRF middleware that does not extend Laravel's is still beyond reach
     * from a package; the README's troubleshooting entry covers that case.
     */
    protected function excludeHooksFromCsrf(): void
    {
        $middleware = class_exists(PreventRequestForgery::class)
            ? PreventRequestForgery::class
            : ValidateCsrfToken::class;

        // The static except() arrived mid-11.x; older versions fall back to the
        // per-route exclusion alone.
        if (method_exists($middleware, 'except')) {
            $middleware::except([Slipstream::path().'/hooks/*']);
        }
    }

    protected function registerRoutes(): void
    {
        Route::group([
            'domain' => config('slipstream.domain'),
            'prefix' => config('slipstream.path', 'slipstream'),
            'middleware' => config('slipstream.middleware', ['web']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'slipstream');
    }

    protected function registerLivewire(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        // The dashboard gate runs on the initial GET via route middleware; make
        // it persist onto Livewire's POST /livewire/update so actions can't be
        // driven from a captured snapshot without re-passing the gate. Each
        // component also re-checks in boot() as defence-in-depth.
        Livewire::addPersistentMiddleware([
            AuthorizeSlipstream::class,
        ]);

        // PCI mode has no dashboard, so its components are not registered at
        // all: an update request naming one has nothing to resolve. The gate
        // (Slipstream::check() is always false there) covers whatever is left.
        if (Slipstream::pciMode()) {
            return;
        }

        Livewire::component('slipstream.project-chrome', ProjectChrome::class);
        Livewire::component('slipstream.deployments-index', DeploymentsIndex::class);
        Livewire::component('slipstream.deployment-show', DeploymentShow::class);
        Livewire::component('slipstream.webhook-page', WebhookPage::class);
        Livewire::component('slipstream.settings-page', SettingsPage::class);
        Livewire::component('slipstream.servers-page', ServersPage::class);
        Livewire::component('slipstream.pipelines-page', PipelinesPage::class);
        Livewire::component('slipstream.deploy-hooks-page', DeployHooksPage::class);
        Livewire::component('slipstream.environment-page', EnvironmentPage::class);
    }

    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Laravel names the overlap mutex after the command alone and keeps
            // it in the default cache store. On a fleet sharing that store, one
            // node's run would make every other node skip its own; these are
            // per-node tasks, so the mutex is per node.
            $perNode = fn (string $task) => 'framework'.DIRECTORY_SEPARATOR.'schedule-'.sha1($task.'|'.Slipstream::serverName());

            // A run killed outright (a reboot, a terminated instance) never
            // releases its mutex, and Laravel's default expiry is a day: a
            // node would stop polling for that long. A heartbeat finishes
            // within about a minute and a half, so five minutes is ample.
            $schedule->command('slipstream:heartbeat')
                ->everyMinute()
                ->name('slipstream-heartbeat')
                ->withoutOverlapping(5)
                ->createMutexNameUsing($perNode('slipstream-heartbeat'))
                // In poll mode the check first waits a random few seconds; in
                // the background that never holds up the app's own tasks.
                ->runInBackground();

            $schedule->command('slipstream:prune')
                ->daily()
                ->name('slipstream-prune')
                ->withoutOverlapping(60)
                ->createMutexNameUsing($perNode('slipstream-prune'));
        });
    }
}
