<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Webhooks\RelayFanout;

/**
 * Fans a stored relay payload out to peers. Run detached from the webhook
 * request so the provider (GitHub/GitLab/Bitbucket) always gets a fast 200,
 * regardless of how slow or unreachable the peers are.
 */
class RelayCommand extends Command
{
    protected $signature = 'slipstream:relay {--delivery= : Webhook delivery id whose relay payload to fan out}';

    protected $description = 'Fan out a stored relay payload to peer servers (detached from the webhook request)';

    public function handle(RelayFanout $relay): int
    {
        $id = $this->option('delivery');
        if (! $id) {
            $this->error('Missing --delivery.');

            return self::FAILURE;
        }

        $delivery = WebhookDelivery::query()->find($id);
        if (! $delivery) {
            $this->error("Delivery [{$id}] not found.");

            return self::FAILURE;
        }

        $fields = data_get($delivery->payload_meta, 'relay_pending');
        if (! is_array($fields)) {
            $this->warn('No pending relay payload — nothing to fan out.');

            return self::SUCCESS;
        }

        $results = $relay->fanOutPayload($fields);

        $delivery->refresh();
        $delivery->update([
            'payload_meta' => array_merge($delivery->payload_meta ?? [], ['relay' => $results]),
        ]);

        $ok = collect($results)->where('ok', true)->count();
        $this->info("Relay fan-out complete: {$ok}/".count($results).' peer(s).');

        return self::SUCCESS;
    }
}
