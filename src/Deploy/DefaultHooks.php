<?php

namespace Slipstream\Deploy;

use Slipstream\Models\DeploymentHook;

/**
 * The hooks Slipstream ships with out of the box. These are the tasks Envoyer
 * makes you add by hand (build assets, migrate, optimize, restart workers) —
 * we seed them so a fresh install deploys a normal Laravel app correctly, but
 * every one is a plain editable hook: reorder it, edit the script, disable it,
 * or delete it. Scripts self-guard (skip cleanly when a tool/package is absent)
 * and use {{ php }} / {{ npm }} so they work off PATH.
 */
class DefaultHooks
{
    /** @var list<array<string, mixed>> */
    public const DEFAULTS = [
        [
            'step' => 'composer',
            'position' => 'after',
            'name' => 'Build assets',
            'sort_order' => 10,
            'script' => "[ -f package.json ] || exit 0\n"
                ."if [ -f package-lock.json ]; then {{ npm }} ci --no-audit --no-fund; else {{ npm }} install --no-audit --no-fund; fi\n"
                ."{{ npm }} run build\n"
                .'rm -rf node_modules',
        ],
        [
            'step' => 'composer',
            'position' => 'after',
            'name' => 'Run migrations',
            'sort_order' => 20,
            'fleet_lock' => true,
            'script' => '{{ php }} artisan migrate --force',
        ],
        [
            'step' => 'composer',
            'position' => 'after',
            'name' => 'Optimize (cache config, routes, views)',
            'sort_order' => 30,
            'script' => '{{ php }} artisan optimize',
        ],
        [
            'step' => 'composer',
            'position' => 'after',
            'name' => 'Link storage',
            'sort_order' => 40,
            'script' => '{{ php }} artisan storage:link || true',
        ],
        [
            'step' => 'fpm',
            'position' => 'after',
            'name' => 'Restart Horizon',
            'sort_order' => 50,
            'script' => "[ -d vendor/laravel/horizon ] || exit 0\n"
                .'{{ php }} artisan horizon:terminate || true',
        ],
        [
            'step' => 'fpm',
            'position' => 'after',
            'name' => 'Restart Reverb',
            'sort_order' => 60,
            'script' => "[ -d vendor/laravel/reverb ] || exit 0\n"
                .'{{ php }} artisan reverb:restart || true',
        ],
    ];

    /**
     * Seed any default hook that isn't already present (matched on step +
     * position + name, so a hook the user renamed or deleted stays that way).
     */
    public static function seed(): void
    {
        foreach (self::DEFAULTS as $hook) {
            DeploymentHook::query()->firstOrCreate(
                [
                    'step' => $hook['step'],
                    'position' => $hook['position'],
                    'name' => $hook['name'],
                ],
                $hook + ['enabled' => true],
            );
        }
    }
}
