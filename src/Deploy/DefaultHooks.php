<?php

namespace Slipstream\Deploy;

use Slipstream\Models\DeploymentHook;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Support\Activity;

/**
 * The hooks Slipstream ships with out of the box. These are the tasks Envoyer
 * makes you add by hand (build assets, migrate, optimize, restart workers) —
 * we seed them so a fresh install deploys a normal Laravel app correctly, but
 * every one is a plain editable hook: reorder it, edit the script, disable it,
 * or delete it. Scripts self-guard (skip cleanly when a tool/package is absent)
 * and use {{ php }} / {{ npm }} so they work off PATH.
 *
 * A script of several lines starts with `set -e`: the shell reports only the
 * last line's status, so without it a failed `npm run build` followed by
 * `rm -rf node_modules` passed (every install before 0.5.2 had that).
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
            'script' => "set -e\n"
                ."[ -f package.json ] || exit 0\n"
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
            'script' => "set -e\n"
                ."[ -d vendor/laravel/horizon ] || exit 0\n"
                .'{{ php }} artisan horizon:terminate || true',
        ],
        [
            'step' => 'fpm',
            'position' => 'after',
            'name' => 'Restart Reverb',
            'sort_order' => 60,
            'script' => "set -e\n"
                ."[ -d vendor/laravel/reverb ] || exit 0\n"
                .'{{ php }} artisan reverb:restart || true',
        ],
    ];

    /**
     * Bumped whenever a default's text changes, so an existing install
     * upgrades its untouched copies once (upgradeOnce).
     */
    public const VERSION = 2;

    /**
     * Every text a default shipped with before the current one, oldest
     * first. A stored copy of any of these is still an untouched default: it
     * steps aside for a hooks file and the migrate step like the current text,
     * and is brought up to date in place. Add the outgoing text here and bump
     * VERSION whenever a default changes.
     *
     * @var array<string, list<string>>
     */
    public const PREVIOUS = [
        'Build assets' => [
            "[ -f package.json ] || exit 0\n"
            ."if [ -f package-lock.json ]; then {{ npm }} ci --no-audit --no-fund; else {{ npm }} install --no-audit --no-fund; fi\n"
            ."{{ npm }} run build\n"
            .'rm -rf node_modules',
        ],
        'Restart Horizon' => [
            "[ -d vendor/laravel/horizon ] || exit 0\n"
            .'{{ php }} artisan horizon:terminate || true',
        ],
        'Restart Reverb' => [
            "[ -d vendor/laravel/reverb ] || exit 0\n"
            .'{{ php }} artisan reverb:restart || true',
        ],
    ];

    public const MIGRATE_HOOK = 'Run migrations';

    public const VERSION_SETTING = 'default_hooks_version';

    /**
     * Seed any default hook that isn't already present (matched on step +
     * position + name, so a hook the user renamed or deleted stays that way),
     * after bringing untouched copies of earlier defaults up to date.
     *
     * @param  list<string>  $except  names of defaults not to seed
     */
    public static function seed(array $except = []): void
    {
        self::upgradeUntouched('seed');

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
        self::upgradeUntouched('install');

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
     * Rewrite stored copies of an earlier default's text to the current text.
     * Only the script changes; enabled, order, servers and the fleet lock
     * stay as they are. Edited copies are the operator's own and are left
     * alone. Returns how many rows changed.
     */
    public static function upgradeUntouched(string $via = 'seed'): int
    {
        $upgraded = [];

        foreach (self::DEFAULTS as $default) {
            $previous = array_map(HookScript::normalize(...), self::PREVIOUS[$default['name']] ?? []);
            if ($previous === []) {
                continue;
            }

            $rows = DeploymentHook::query()
                ->where('step', $default['step'])
                ->where('position', $default['position'])
                ->where('name', $default['name'])
                ->get();

            foreach ($rows as $row) {
                if (in_array(HookScript::normalize($row->script), $previous, true)) {
                    $row->update(['script' => $default['script']]);
                    $upgraded[] = $default['name'];
                }
            }
        }

        if ($upgraded !== []) {
            Activity::log('hook.defaults_upgraded', [
                'hooks' => array_values(array_unique($upgraded)),
                'version' => self::VERSION,
                'via' => $via,
            ]);
        }

        return count($upgraded);
    }

    /**
     * upgradeUntouched() once per database: a release that changes a seeded
     * hook's text reaches existing installs from the minute heartbeat, since
     * install seeds only what is missing and nobody re-runs it. One settings
     * read per heartbeat; null when nothing was due.
     */
    public static function upgradeOnce(SettingsRepository $settings): ?int
    {
        if ((int) $settings->get(self::VERSION_SETTING, 0) >= self::VERSION) {
            return null;
        }

        $count = self::upgradeUntouched('heartbeat');
        $settings->set(self::VERSION_SETTING, (string) self::VERSION);

        return $count;
    }

    /**
     * The texts a default has ever shipped with: the current one first.
     *
     * @return list<string>
     */
    public static function scriptsFor(string $name): array
    {
        $current = [];
        foreach (self::DEFAULTS as $default) {
            if ($default['name'] === $name) {
                $current[] = $default['script'];
            }
        }

        return [...$current, ...(self::PREVIOUS[$name] ?? [])];
    }

    /**
     * A seeded default nobody has edited: same step, position, name and a
     * script equal to the current text or one it shipped with before. Edited
     * defaults are the operator's own hooks now, and run.
     */
    public static function isUntouchedDefault(DeploymentHook $hook, ?string $name = null): bool
    {
        if ($hook->source !== 'database') {
            return false;
        }

        $script = HookScript::normalize($hook->script);

        foreach (self::DEFAULTS as $default) {
            if ($name !== null && $default['name'] !== $name) {
                continue;
            }

            if ($hook->step === $default['step']
                && $hook->position === $default['position']
                && $hook->name === $default['name']
                && in_array($script, array_map(HookScript::normalize(...), self::scriptsFor($default['name'])), true)) {
                return true;
            }
        }

        return false;
    }
}
