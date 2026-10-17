<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Models\ActivityLog;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Tests\TestCase;

class PipelinesCommandTest extends TestCase
{
    public function test_add_edit_toggle_and_delete_by_name_or_id(): void
    {
        Server::query()->create(['server_name' => 'web-1', 'enabled' => true, 'paused' => false]);

        $this->artisan('slipstream:pipelines add --name=release --branch="release/*" --auto-deploy=0 --servers=web-1')
            ->expectsOutputToContain('added for release/*')
            ->assertExitCode(0);

        $pipeline = Pipeline::query()->where('name', 'release')->first();
        $this->assertSame('release/*', $pipeline->branch_pattern);
        $this->assertFalse($pipeline->auto_deploy);
        $this->assertTrue($pipeline->enabled);
        $this->assertSame(['web-1'], $pipeline->server_names);
        $this->assertTrue($pipeline->matchesRef('refs/heads/release/2026.10'));

        $this->artisan('slipstream:pipelines edit release --auto-deploy=1 --servers=all')->assertExitCode(0);
        $pipeline->refresh();
        $this->assertTrue($pipeline->auto_deploy);
        $this->assertNull($pipeline->server_names);

        $this->artisan("slipstream:pipelines disable {$pipeline->id}")->assertExitCode(0);
        $this->assertFalse($pipeline->fresh()->enabled);

        $this->artisan('slipstream:pipelines')
            ->expectsOutputToContain('release/*')
            ->assertExitCode(0);

        $this->artisan('slipstream:pipelines delete release')
            ->expectsConfirmation('Delete pipeline "release"? Pushes matching release/* will be ignored.', 'yes')
            ->assertExitCode(0);
        $this->assertSame(0, Pipeline::query()->count());
        $this->assertTrue(ActivityLog::query()->where('action', 'pipeline.deleted')->exists());
    }

    public function test_validation_and_lookups_fail_loudly(): void
    {
        $this->artisan('slipstream:pipelines add --name=x')
            ->expectsOutputToContain('branch pattern is required')
            ->assertExitCode(1);

        $this->artisan('slipstream:pipelines enable nope')
            ->expectsOutputToContain('No pipeline "nope"')
            ->assertExitCode(1);

        $this->artisan('slipstream:pipelines')
            ->expectsOutputToContain('every push is ignored')
            ->assertExitCode(0);
    }
}
