<?php

namespace Slipstream\Livewire;

use Livewire\Attributes\Locked;
use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentEvent;

class DeploymentShow extends SlipstreamComponent
{
    #[Locked]
    public int $deploymentId;

    public int $lastSequence = 0;

    public function mount(int $deploymentId): void
    {
        $this->deploymentId = $deploymentId;
    }

    public function render()
    {
        $deployment = Deployment::query()->findOrFail($this->deploymentId);

        $tail = max(50, (int) config('slipstream.logging.viewer_tail', 800));
        $total = DeploymentEvent::query()->where('deployment_id', $deployment->id)->count();

        // Render only the most recent $tail lines so the query and the payload
        // stay bounded no matter how many events the deploy produced.
        $events = DeploymentEvent::query()
            ->where('deployment_id', $deployment->id)
            ->orderByDesc('sequence')
            ->limit($tail)
            ->get()
            ->sortBy('sequence')
            ->values();

        if ($events->isNotEmpty()) {
            $this->lastSequence = (int) $events->last()->sequence;
        }

        return view('slipstream::livewire.deployment-show', [
            'deployment' => $deployment,
            'events' => $events,
            'truncated' => $total > $events->count() ? $total - $events->count() : 0,
            'pollingMs' => $deployment->isFinished()
                ? 0
                : (int) config('slipstream.realtime.polling_ms', 1500),
        ]);
    }
}
