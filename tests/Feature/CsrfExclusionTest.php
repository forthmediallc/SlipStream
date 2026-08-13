<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use ReflectionClass;
use Slipstream\Tests\TestCase;

class CsrfExclusionTest extends TestCase
{
    protected function csrfClass(): string
    {
        return class_exists(PreventRequestForgery::class)
            ? PreventRequestForgery::class
            : ValidateCsrfToken::class;
    }

    /**
     * @return list<string>
     */
    protected function neverVerify(): array
    {
        $property = (new ReflectionClass($this->csrfClass()))->getProperty('neverVerify');
        $property->setAccessible(true);

        return $property->getValue();
    }

    protected function tearDown(): void
    {
        // The exclusion list is static; leaving entries behind would leak into
        // other tests.
        if (method_exists($this->csrfClass(), 'flushState')) {
            $this->csrfClass()::flushState();
        }

        parent::tearDown();
    }

    public function test_hook_paths_are_excluded_at_the_framework_level(): void
    {
        if (! method_exists($this->csrfClass(), 'except')) {
            $this->markTestSkipped('this Laravel version has no static CSRF exclusion.');
        }

        $this->assertContains('slipstream/hooks/*', $this->neverVerify());
    }

    /**
     * The static list lives on the shared base class, which is why a host app
     * registering its own CSRF subclass still gets the exclusion — the exact
     * case where the per-route withoutMiddleware() does nothing.
     */
    public function test_the_exclusion_is_visible_through_a_subclass(): void
    {
        if (! method_exists($this->csrfClass(), 'except')) {
            $this->markTestSkipped('this Laravel version has no static CSRF exclusion.');
        }

        $property = (new ReflectionClass(ValidateCsrfToken::class))->getProperty('neverVerify');
        $property->setAccessible(true);

        $this->assertContains('slipstream/hooks/*', $property->getValue());
    }

    public function test_it_follows_the_configured_dashboard_path(): void
    {
        if (! method_exists($this->csrfClass(), 'except')) {
            $this->markTestSkipped('this Laravel version has no static CSRF exclusion.');
        }

        $this->csrfClass()::flushState();

        config()->set('slipstream.path', 'deploys');
        (new \Slipstream\SlipstreamServiceProvider($this->app))->boot();

        $this->assertContains('deploys/hooks/*', $this->neverVerify());
    }
}
