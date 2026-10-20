<?php

use Illuminate\Support\Facades\Route;
use Slipstream\Http\Controllers\DashboardController;
use Slipstream\Http\Middleware\AuthorizeSlipstream;
use Slipstream\Slipstream;

// The dashboard. The hook endpoints live in routes/hooks.php, under their own
// prefix (Slipstream::hooksPath()).
//
// PCI mode: no dashboard. The routes do not exist, so there is no "disabled"
// response to probe and no gate to get past.
if (Slipstream::pciMode()) {
    return;
}

// Gated by the Slipstream::auth() callback / viewSlipstream gate.
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
