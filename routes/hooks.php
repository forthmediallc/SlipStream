<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Slipstream\Http\Controllers\WebhookController;
use Slipstream\Http\Middleware\RestrictWebhookSource;
use Slipstream\Slipstream;

// The hook endpoints, registered under Slipstream::hooksPath(): {path}/hooks by
// default, or SLIPSTREAM_HOOKS_PATH so an SSO proxy in front of the dashboard
// never stands in front of the git host's deliveries.
//
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

// The provider hook exists in webhook mode only. Its optional source
// allow-list runs after the throttle and before the body is read. In poll
// mode a node learns about pushes by itself, so there is nothing to receive.
if (! Slipstream::pollMode()) {
    Route::post('/deploy', [WebhookController::class, 'deploy'])
        ->withoutMiddleware($hookCsrf)
        ->middleware([$hookThrottle, RestrictWebhookSource::class])
        ->name('slipstream.hooks.deploy');
}

// PCI mode: nothing below is registered. Peer traffic (relay, env-sync and
// deploy-sync nudges) 404s — there is no "disabled" response to probe, and no
// gate to get past, because the routes do not exist. With poll mode on top,
// Slipstream registers no route at all.
if (Slipstream::pciMode()) {
    return;
}

// Relay spreads one provider webhook across the fleet; with no webhook there
// is nothing to spread.
if (! Slipstream::pollMode()) {
    Route::post('/relay', [WebhookController::class, 'relay'])
        ->withoutMiddleware($hookCsrf)
        ->middleware($hookThrottle)
        ->name('slipstream.hooks.relay');
}
Route::post('/env-sync', [WebhookController::class, 'envSync'])
    ->withoutMiddleware($hookCsrf)
    ->middleware($hookThrottle)
    ->name('slipstream.hooks.env-sync');
Route::post('/deploy-sync', [WebhookController::class, 'deploySync'])
    ->withoutMiddleware($hookCsrf)
    ->middleware($hookThrottle)
    ->name('slipstream.hooks.deploy-sync');
