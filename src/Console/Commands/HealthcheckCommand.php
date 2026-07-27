<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Slipstream\Slipstream;

class HealthcheckCommand extends Command
{
    protected $signature = 'slipstream:healthcheck';

    protected $description = 'Pre-swap health gate: DB, Redis, and app-registered checks';

    public function handle(): int
    {
        $this->components->info('Running Slipstream health checks…');

        try {
            DB::connection()->getPdo();
            $this->components->twoColumnDetail('Database', '<fg=green>OK</>');
        } catch (\Throwable $e) {
            $this->components->error('Database: '.$e->getMessage());

            return self::FAILURE;
        }

        $redisRequired = in_array(config('cache.default'), ['redis'], true)
            || config('queue.default') === 'redis'
            || config('session.driver') === 'redis';

        try {
            Redis::connection()->ping();
            $this->components->twoColumnDetail('Redis', '<fg=green>OK</>');
        } catch (\Throwable $e) {
            if ($redisRequired) {
                $this->components->error('Redis: '.$e->getMessage());

                return self::FAILURE;
            }
            $this->components->twoColumnDetail('Redis', '<fg=yellow>skipped</>');
        }

        foreach (Slipstream::$healthChecks as $name => $callback) {
            try {
                $callback();
                $this->components->twoColumnDetail($name, '<fg=green>OK</>');
            } catch (\Throwable $e) {
                $this->components->error("{$name}: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->components->info('All health checks passed.');

        return self::SUCCESS;
    }
}
