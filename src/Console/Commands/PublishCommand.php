<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;

class PublishCommand extends Command
{
    protected $signature = 'slipstream:publish
                            {--config : Publish only config/slipstream.php}
                            {--assets : Publish only dashboard assets}
                            {--migrations : Publish only migrations}
                            {--force : Overwrite existing files}';

    protected $description = 'Publish Slipstream config, assets, and/or migrations';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $onlyConfig = (bool) $this->option('config');
        $onlyAssets = (bool) $this->option('assets');
        $onlyMigrations = (bool) $this->option('migrations');
        $all = ! $onlyConfig && ! $onlyAssets && ! $onlyMigrations;

        if ($all || $onlyConfig) {
            $this->call('vendor:publish', [
                '--tag' => 'slipstream-config',
                '--force' => $force,
            ]);
        }

        if ($all || $onlyAssets) {
            $this->call('vendor:publish', [
                '--tag' => 'slipstream-assets',
                '--force' => $force || $all,
            ]);
        }

        if ($onlyMigrations) {
            $this->call('vendor:publish', [
                '--tag' => 'slipstream-migrations',
                '--force' => $force,
            ]);
        }

        $this->components->info('Slipstream publish complete.');

        return self::SUCCESS;
    }
}
