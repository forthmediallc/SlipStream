<?php

namespace Slipstream\Livewire;

use Livewire\Component;
use Slipstream\Slipstream;

/**
 * Base class for every dashboard Livewire component.
 *
 * Route middleware only guards the initial full-page GET. Subsequent Livewire
 * requests hit POST /livewire/update, which re-applies only Livewire's
 * persistent middleware list — so the dashboard gate is ALSO enforced here, on
 * every hydration and action, as defence-in-depth. This closes the hole where a
 * captured component snapshot could drive mutations (deploy, rollback, hook
 * scripts, .env writes, secret reveal) without ever re-passing the gate.
 */
abstract class SlipstreamComponent extends Component
{
    public function boot(): void
    {
        abort_unless(Slipstream::check(request()), 403);
    }
}
