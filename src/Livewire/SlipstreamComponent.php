<?php

namespace Slipstream\Livewire;

use Livewire\Component;
use Slipstream\Models\Deployment;
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

    /**
     * One flash line for a fleet-wide action queued through DeployQueue:
     * local outcome + peers queued + servers skipped as ineligible.
     *
     * @param  array{batch: string, local: ?Deployment, queued: list<string>, skipped: list<string>}  $result
     */
    protected function flashFleetResult(string $verb, array $result): void
    {
        $local = $result['local'];

        if (! $local && $result['queued'] === []) {
            session()->flash(
                'slipstream_error',
                $verb.' not started: no eligible servers'
                .($result['skipped'] !== [] ? ' (paused/disabled: '.implode(', ', $result['skipped']).')' : '.')
            );

            return;
        }

        if ($local && $local->status === Deployment::STATUS_FAILED) {
            session()->flash(
                'slipstream_error',
                "{$verb} #{$local->id} failed to start here: ".($local->failure_reason ?: 'spawn failed')
                .($result['queued'] !== [] ? ' — still queued for '.implode(', ', $result['queued']) : '')
            );

            return;
        }

        $parts = [];
        if ($local) {
            $parts[] = "{$verb} #{$local->id} started on ".Slipstream::serverName();
        }
        if ($result['queued'] !== []) {
            $parts[] = 'queued for '.implode(', ', $result['queued']);
        }
        if ($result['skipped'] !== []) {
            $parts[] = 'skipped (paused/disabled): '.implode(', ', $result['skipped']);
        }

        session()->flash('slipstream_status', implode(' · ', $parts).'.');
    }
}
