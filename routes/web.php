<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Slipstream\Http\Controllers\DashboardController;
use Slipstream\Http\Controllers\WebhookController;
use Slipstream\Http\Middleware\AuthorizeSlipstream;

// Unauthenticated — signature/token verified inside the controller. These are
// machine-to-machine POSTs (GitHub/GitLab/Bitbucket providers, peer relay, and
// peer env-sync / deploy-sync nudges) that never carry a CSRF token, so the web
// group's CSRF filter must be excluded or every delivery 419s. Rate limited to
// blunt abuse of the open endpoints.
$hookThrottle = 'throttle:'.config('slipstream.webhook.throttle', '60,1');

Route::post('/hooks/deploy', [WebhookController::class, 'deploy'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware($hookThrottle)
    ->name('slipstream.hooks.deploy');
Route::post('/hooks/relay', [WebhookController::class, 'relay'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware($hookThrottle)
    ->name('slipstream.hooks.relay');
Route::post('/hooks/env-sync', [WebhookController::class, 'envSync'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware($hookThrottle)
    ->name('slipstream.hooks.env-sync');
Route::post('/hooks/deploy-sync', [WebhookController::class, 'deploySync'])
    ->withoutMiddleware([ValidateCsrfToken::class])
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
