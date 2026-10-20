<?php

namespace Slipstream\Deploy;

use RuntimeException;
use Slipstream\Models\DeploymentHook;
use Slipstream\Slipstream;

/**
 * Deploy hooks from a file the app repository carries (slipstream-hooks.php
 * by default), read from the release being deployed. The commit that needs a
 * hook ships it, and a node with an empty database runs exactly what an old
 * one runs. The file returns a list of arrays:
 *
 *     return [
 *         ['name' => 'Build assets', 'step' => 'composer', 'script' => '{{ npm }} ci && {{ npm }} run build'],
 *         ['name' => 'Warm cache', 'step' => 'swap', 'position' => 'before', 'script' => '{{ php }} artisan app:warm'],
 *     ];
 *
 * Keys: name, step and script (required); position (before|after, default
 * after), servers (list of server names; default all), fleet_lock (run on
 * one node per commit), enabled (default true), fail_fast (default true: a
 * script of several lines gets `set -e` first, so its first failing line
 * fails the deploy rather than only its last), where (node, the default, or
 * build: run by `slipstream:artifact package` in CI and never on an artifact
 * node; only on the fetch and composer steps). A "before fetch" hook cannot
 * come from here — the release it lives in does not exist yet at that point.
 */
final class HookFile
{
    public const WHERE = ['node', 'build'];

    /** The steps a build-time hook can attach to: what happens before the release is a tree. */
    public const BUILD_STEPS = ['fetch', 'composer'];

    public static function path(string $releaseRoot): string
    {
        $file = trim((string) config('slipstream.hooks.file', 'slipstream-hooks.php'));

        if ($file !== '' && ($file[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $file) === 1)) {
            // Only the verified tree may supply code to a node in artifact mode.
            if (Slipstream::artifactMode()) {
                throw new RuntimeException("SLIPSTREAM_HOOKS_FILE is absolute ({$file}); in artifact mode the hooks file must be inside the release.");
            }

            return $file;
        }

        return rtrim($releaseRoot, '/\\').DIRECTORY_SEPARATOR.ltrim($file, '/\\');
    }

    public static function exists(string $releaseRoot): bool
    {
        return is_file(self::path($releaseRoot));
    }

    /**
     * @return list<DeploymentHook>|null  null when the release has no hooks file
     *
     * @throws RuntimeException  a file that is there but wrong fails the deploy
     *                           rather than silently building something else
     */
    public static function load(string $releaseRoot): ?array
    {
        $file = self::path($releaseRoot);

        if (! is_file($file)) {
            return null;
        }

        $label = basename($file);

        try {
            // Isolated scope: the file sees none of the caller's variables.
            $definitions = (static fn (string $path) => require $path)($file);
        } catch (\Throwable $e) {
            throw new RuntimeException("{$label} could not be loaded: ".$e->getMessage(), 0, $e);
        }

        if (! is_array($definitions) || ! array_is_list($definitions)) {
            throw new RuntimeException("{$label} must return a list of hooks.");
        }

        $hooks = [];

        foreach ($definitions as $index => $definition) {
            $hooks[] = self::hook($label, $index + 1, $definition);
        }

        return $hooks;
    }

    protected static function hook(string $label, int $number, mixed $definition): DeploymentHook
    {
        $where = "{$label}, hook #{$number}";

        if (! is_array($definition)) {
            throw new RuntimeException("{$where} must be an array.");
        }

        $name = trim((string) ($definition['name'] ?? ''));
        $step = (string) ($definition['step'] ?? '');
        $position = (string) ($definition['position'] ?? 'after');
        $script = trim((string) ($definition['script'] ?? ''));
        $servers = $definition['servers'] ?? null;

        if ($name === '') {
            throw new RuntimeException("{$where} needs a name.");
        }
        if (! in_array($step, DeploySteps::STEPS, true)) {
            throw new RuntimeException("{$where} (\"{$name}\") has an unknown step \"{$step}\"; one of ".implode(', ', DeploySteps::STEPS).'.');
        }
        if (! in_array($position, DeploySteps::POSITIONS, true)) {
            throw new RuntimeException("{$where} (\"{$name}\"): position must be before or after.");
        }
        if ($step === 'fetch' && $position === 'before') {
            throw new RuntimeException("{$where} (\"{$name}\"): a before-fetch hook cannot come from the release it would run before. Keep it in the database (slipstream:hooks add).");
        }
        if ($script === '') {
            throw new RuntimeException("{$where} (\"{$name}\") needs a script.");
        }
        if ($servers !== null && (! is_array($servers) || array_filter($servers, fn ($s) => ! is_string($s)) !== [])) {
            throw new RuntimeException("{$where} (\"{$name}\"): servers must be a list of server names.");
        }
        $failFast = $definition['fail_fast'] ?? true;
        if (! is_bool($failFast)) {
            throw new RuntimeException("{$where} (\"{$name}\"): fail_fast must be true or false.");
        }
        $runsWhere = $definition['where'] ?? 'node';
        if (! is_string($runsWhere) || ! in_array($runsWhere, self::WHERE, true)) {
            throw new RuntimeException("{$where} (\"{$name}\"): where must be node or build.");
        }
        if ($runsWhere === 'build' && ! in_array($step, self::BUILD_STEPS, true)) {
            throw new RuntimeException("{$where} (\"{$name}\"): a build-time hook can only attach to fetch or composer; {$step} happens on the node.");
        }

        // Applied here rather than when the hook runs, so what `slipstream:hooks`
        // lists is exactly what a deploy executes.
        $hook = new DeploymentHook([
            'step' => $step,
            'position' => $position,
            'name' => $name,
            'script' => $failFast ? HookScript::withFailFast($script) : $script,
            'fleet_lock' => (bool) ($definition['fleet_lock'] ?? false),
            'enabled' => (bool) ($definition['enabled'] ?? true),
            'server_names' => $servers === null ? null : array_values($servers),
            'sort_order' => $number,
        ]);
        $hook->source = 'file';
        $hook->where = $runsWhere;

        return $hook;
    }
}
