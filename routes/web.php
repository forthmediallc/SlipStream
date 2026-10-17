<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Slipstream\Http\Controllers\DashboardController;
use Slipstream\Http\Controllers\WebhookController;
use Slipstream\Http\Middleware\AuthorizeSlipstream;
use Slipstream\Http\Middleware\RestrictWebhookSource;
use Slipstream\Slipstream;

// Unauthenticated — signature/token verified inside the controller. These are
// machine-to-machine POSTs (GitHub/GitLab/Bitbucket providers, peer relay, and
// peer env-sync / deploy-sync nudges) that never carry a CSRF token, so the web
// group's CSRF filter must be excluded or every delivery 419s. Rate limited to
// blunt abuse of the open endpoints.
//
// Both CSRF class names are listed because withoutMiddleware() strips by
// registered class (or subclass): newer frameworks register the web group with
// PreventRequestForgery, whose deprecated ValidateCsrfToken alias is its CHILD
// — naming only the alias strips nothing there. Naming a class the installed
// framework doesn't have is harmless (::class is just a string).
$hookCsrf = [ValidateCsrfToken::class, PreventRequestForgery::class];
$hookThrottle = 'throttle:'.config('slipstream.webhook.throttle', '60,1');

// The provider hook is the one route that exists in every mode. Its optional
// source allow-list runs after the throttle and before the body is read.
Route::post('/hooks/deploy', [WebhookController::class, 'deploy'])
    ->withoutMiddleware($hookCsrf)
    ->middleware([$hookThrottle, RestrictWebhookSource::class])
    ->name('slipstream.hooks.deploy');

// PCI mode: nothing below is registered. Peer traffic (relay, env-sync and
// deploy-sync nudges) and the dashboard 404 — there is no "disabled" response
// to probe, and no gate to get past, because the routes do not exist.
if (Slipstream::pciMode()) {
    return;
}

Route::post('/hooks/relay', [WebhookController::class, 'relay'])
    ->withoutMiddleware($hookCsrf)
    ->middleware($hookThrottle)
    ->name('slipstream.hooks.relay');
Route::post('/hooks/env-sync', [WebhookController::class, 'envSync'])
    ->withoutMiddleware($hookCsrf)
    ->middleware($hookThrottle)
    ->name('slipstream.hooks.env-sync');
Route::post('/hooks/deploy-sync', [WebhookController::class, 'deploySync'])
    ->withoutMiddleware($hookCsrf)
    ->middleware($hookThrottle)
    ->name('slipstream.hooks.deploy-sync');

// Dashboard — gated by the Slipstream::auth() callback / viewSlipstream gate.
Route::middleware([AuthorizeSlipstream::class])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('slipstream.dashboard');
    Route::get('/deployments', [DashboardController::class, 'deployments'])->name('slipstream.deployments');
    Route::get('/deployments/{deployment}', [DashboardController::class, 'deployment'])->whereNumber('deployment')->name('slipstream.deployment');
    Route::get('/servers', [DashboardController::class, 'servers'])->name('slipstream.servers');
    Route::get('/pipelines', [DashboardController::class, 'pipelines'])->name('slipstream.pipelines');
    Route::get('/deploy-hooks', [DashboardController::class, 'hooks'])->name('slipstream.hooks');
    Route::get('/webhook', [DashboardController::class, 'webhook'])->name('slipstream.webhook');
    Route::get('/settings', [DashboardController::class, 'settings'])->name('slipstream.settings');
    Route::get('/environment', [DashboardController::class, 'environment'])->name('slipstream.environment');
});
