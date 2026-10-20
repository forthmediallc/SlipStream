<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Slipstream;
use Slipstream\Tests\TestCase;

class ConfigAndRoutingTest extends TestCase
{
    public function test_view_enabled_defaults_true_for_unknown_sections(): void
    {
        // A config published before a section existed must not 404 a nav link.
        $this->assertTrue(Slipstream::viewEnabled('some_future_section'));
    }

    public function test_index_redirects_to_first_enabled_section_when_deployments_disabled(): void
    {
        Slipstream::auth(fn () => true);
        config()->set('slipstream.views.deployments', false);

        $this->get('/slipstream')->assertRedirect(route('slipstream.servers'));
    }

    public function test_non_numeric_deployment_id_is_404_not_500(): void
    {
        Slipstream::auth(fn () => true);

        $this->get('/slipstream/deployments/not-a-number')->assertNotFound();
    }

    public function test_the_hooks_path_defaults_to_under_the_dashboard(): void
    {
        $this->assertSame('slipstream/hooks', Slipstream::hooksPath());
        $this->assertSame('http://localhost/slipstream/hooks/deploy', Slipstream::hookUrl('deploy'));

        config()->set('slipstream.path', 'deploys');
        $this->assertSame('deploys/hooks', Slipstream::hooksPath());

        config()->set('slipstream.hooks_path', '/_hooks/');
        $this->assertSame('_hooks', Slipstream::hooksPath());
        $this->assertSame('_hooks/relay', Slipstream::hookPath('relay'));
    }
}
