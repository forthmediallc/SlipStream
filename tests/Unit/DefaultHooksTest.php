<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Deploy\DefaultHooks;
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
}
