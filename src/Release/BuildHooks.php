<?php

namespace Slipstream\Release;

use Closure;
use RuntimeException;
use Slipstream\Deploy\DefaultHooks;
use Slipstream\Deploy\Deployer;
use Slipstream\Deploy\HookFile;
use Slipstream\Deploy\HookScript;
use Slipstream\Models\DeploymentHook;
use Symfony\Component\Process\Process;

/**
 * The build-time hooks (`where => build`) of a checkout, run by the packager
 * in CI in the order a node would have run them: after fetch, before
 * composer, after composer. From the checkout's hooks file, or the built-in
 * defaults when it has none; `servers` and `fleet_lock` mean nothing at
 * build time and are ignored.
 */
class BuildHooks
{
    public const PHASES = ['after-fetch', 'before-composer', 'after-composer'];

    /**
     * @return list<DeploymentHook>  the build-time hooks, in run order
     */
    public static function of(string $checkout): array
    {
        $hooks = HookFile::load($checkout) ?? DefaultHooks::builtIn();

        return array_values(array_filter($hooks, fn (DeploymentHook $hook) => $hook->where === 'build' && $hook->enabled));
    }

    /**
     * Run one phase's hooks in the checkout.
     *
     * @param  Closure(string): void  $say
     * @return list<string>  the names run
     */
    public static function run(string $checkout, string $sha, string $ref, string $phase, Closure $say): array
    {
        [$position, $step] = explode('-', $phase, 2);
        $ran = [];

        foreach (self::of($checkout) as $hook) {
            if ($hook->step !== $step || $hook->position !== $position) {
                continue;
            }

            $say("Build hook [{$position} {$step}] {$hook->name}");
            $script = HookScript::render((string) $hook->script, [
                'release' => $checkout,
                'app_root' => $checkout,
                'sha' => $sha,
                'branch' => preg_replace('#^refs/(heads|tags)/#', '', $ref) ?? $ref,
                'php' => PHP_BINARY,
                'npm' => (string) config('slipstream.deploy.npm_binary', 'npm'),
                'composer' => (string) config('slipstream.deploy.composer_binary', 'composer'),
            ]);

            $process = Process::fromShellCommandline($script, $checkout, null, null, Deployer::HOOK_TIMEOUT);
            $process->run(function (string $type, string $chunk) use ($say) {
                foreach (preg_split("/\r\n|\n|\r/", $chunk) ?: [] as $line) {
                    if ($line !== '') {
                        $say('  '.$line);
                    }
                }
            });

            if (! $process->isSuccessful()) {
                throw new RuntimeException("Build hook \"{$hook->name}\" failed (exit {$process->getExitCode()}).");
            }

            $ran[] = $hook->name;
        }

        return $ran;
    }
}
