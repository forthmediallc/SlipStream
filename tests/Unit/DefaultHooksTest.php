<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Deploy\DefaultHooks;
use Slipstream\Deploy\HookScript;
use Slipstream\Models\ActivityLog;
use Slipstream\Models\DeploymentHook;
use Slipstream\Tests\TestCase;

class DefaultHooksTest extends TestCase
{
    public function test_seed_creates_the_shipped_default_hooks(): void
    {
        DefaultHooks::seed();

        $this->assertSame(count(DefaultHooks::DEFAULTS), DeploymentHook::query()->count());

        // The migrate default must ship fleet-locked so a multi-server fleet
        // never double-runs the schema for one commit.
        $migrate = DeploymentHook::query()->where('name', 'Run migrations')->first();
        $this->assertNotNull($migrate);
        $this->assertTrue($migrate->fleet_lock);
        $this->assertSame('composer', $migrate->step);
        $this->assertSame('after', $migrate->position);
    }

    public function test_seed_is_idempotent_and_preserves_user_edits(): void
    {
        DefaultHooks::seed();
        $count = DeploymentHook::query()->count();

        // A user disables and rewrites the assets hook…
        $assets = DeploymentHook::query()->where('name', 'Build assets')->firstOrFail();
        $assets->update(['enabled' => false, 'script' => 'echo custom']);

        // …re-seeding (e.g. on the next install) must not duplicate or revert it.
        DefaultHooks::seed();

        $this->assertSame($count, DeploymentHook::query()->count());
        $assets->refresh();
        $this->assertFalse($assets->enabled);
        $this->assertSame('echo custom', $assets->script);
    }

    /**
     * sh -c reports the LAST line's status: without set -e a failed
     * `npm run build` followed by `rm -rf node_modules` passed.
     */
    public function test_the_defaults_that_run_several_lines_fail_fast(): void
    {
        foreach (DefaultHooks::DEFAULTS as $default) {
            if (HookScript::isMultiLine($default['script'])) {
                $this->assertTrue(HookScript::failsFast($default['script']), $default['name']);
            }
        }

        $this->assertStringStartsWith("set -e\n", DefaultHooks::scriptsFor('Build assets')[0]);
    }

    public function test_a_stored_copy_of_an_earlier_default_is_still_untouched(): void
    {
        $old = new DeploymentHook(['step' => 'composer', 'position' => 'after', 'name' => 'Build assets', 'script' => DefaultHooks::PREVIOUS['Build assets'][0]]);
        $edited = new DeploymentHook(['step' => 'composer', 'position' => 'after', 'name' => 'Build assets', 'script' => 'echo custom']);

        $this->assertTrue(DefaultHooks::isUntouchedDefault($old));
        $this->assertFalse(DefaultHooks::isUntouchedDefault($edited));
    }

    public function test_seed_upgrades_untouched_rows_in_place_and_leaves_edited_ones(): void
    {
        DefaultHooks::seed();
        $count = DeploymentHook::query()->count();
        DeploymentHook::query()->where('name', 'Build assets')->update(['script' => DefaultHooks::PREVIOUS['Build assets'][0], 'enabled' => false]);
        DeploymentHook::query()->where('name', 'Restart Horizon')->update(['script' => 'echo custom']);

        DefaultHooks::seed();

        $this->assertSame($count, DeploymentHook::query()->count());
        $assets = DeploymentHook::query()->where('name', 'Build assets')->sole();
        $this->assertStringStartsWith("set -e\n", $assets->script);
        $this->assertFalse($assets->enabled, 'only the text changes');
        $this->assertSame('echo custom', DeploymentHook::query()->where('name', 'Restart Horizon')->sole()->script);
        $this->assertTrue(ActivityLog::query()->where('action', 'hook.defaults_upgraded')->exists());
    }

    public function test_no_previous_text_equals_the_current_one(): void
    {
        foreach (DefaultHooks::PREVIOUS as $name => $texts) {
            $current = HookScript::normalize(DefaultHooks::scriptsFor($name)[0]);
            foreach ($texts as $text) {
                $this->assertNotSame($current, HookScript::normalize($text), $name);
            }
        }
    }
}
