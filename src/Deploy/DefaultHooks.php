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

    public const MIGRATE_HOOK = 'Run migrations';

    /**
     * Seed any default hook that isn't already present (matched on step +
     * position + name, so a hook the user renamed or deleted stays that way).
     *
     * @param  list<string>  $except  names of defaults not to seed
     */
    public static function seed(array $except = []): void
    {
        foreach (self::DEFAULTS as $hook) {
            if (in_array($hook['name'], $except, true)) {
                continue;
            }

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

    /**
     * Seed what an install at this release root should have: nothing when the
     * release carries a hooks file (it replaces the defaults), the defaults
     * minus "Run migrations" when the migrate step is configured, otherwise
     * all of them. Returns what was done, for the install summary.
     */
    public static function seedFor(string $releaseRoot): string
    {
        if (HookFile::exists($releaseRoot)) {
            return 'from '.basename(HookFile::path($releaseRoot)).' (defaults not seeded)';
        }

        if (MigrationCoordinator::command() !== null) {
            self::seed([self::MIGRATE_HOOK]);

            return 'defaults seeded; migrations run in the migrate step';
        }

        self::seed();

        return 'defaults seeded';
    }

    /**
     * A seeded default nobody has edited: same step, position, name and
     * script. Edited defaults are the operator's own hooks now, and run.
     */
    public static function isUntouchedDefault(DeploymentHook $hook, ?string $name = null): bool
    {
        if ($hook->source !== 'database') {
            return false;
        }

        $normalize = fn (?string $script) => trim(str_replace(["\r\n", "\r"], "\n", (string) $script));

        foreach (self::DEFAULTS as $default) {
            if ($name !== null && $default['name'] !== $name) {
                continue;
            }

            if ($hook->step === $default['step']
                && $hook->position === $default['position']
                && $hook->name === $default['name']
                && $normalize($hook->script) === $normalize($default['script'])) {
                return true;
            }
        }

        return false;
    }
}
