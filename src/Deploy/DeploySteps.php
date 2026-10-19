<?php

namespace Slipstream\Deploy;

/**
 * The locked structural steps of a deploy, in order. Hooks attach before or
 * after one of them; the dashboard's Hooks page and `slipstream:hooks` both
 * read the list from here so they can never disagree about what exists.
 */
final class DeploySteps
{
    /** @var list<string> */
    public const STEPS = ['fetch', 'link_shared', 'composer', 'migrate', 'health', 'swap', 'fpm', 'prune'];

    /** @var list<string> */
    public const POSITIONS = ['before', 'after'];

    /** Ordered built-in steps: key => [label, what it does]. */
    public const META = [
        'fetch' => ['Clone new release', 'git archive of the target commit into a fresh release dir'],
        'link_shared' => ['Link shared paths', '.env, storage/, and any shared directories'],
        'composer' => ['Install Composer dependencies', 'composer install --no-dev --optimize-autoloader'],
        'migrate' => ['Run migrations', 'SLIPSTREAM_MIGRATE_COMMAND, once per commit across the fleet (skipped when unset)'],
        'health' => ['Health check', 'php artisan slipstream:healthcheck — gates the swap'],
        'swap' => ['Activate new release', 'atomic symlink swap: current → new release'],
        'fpm' => ['Reload PHP-FPM', 'graceful systemctl reload (connections kept)'],
        'prune' => ['Purge old releases', 'keep the newest N releases'],
    ];

    public static function label(string $step): string
    {
        return self::META[$step][0] ?? $step;
    }
}
