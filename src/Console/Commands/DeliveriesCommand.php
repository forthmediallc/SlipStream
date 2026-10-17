<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Models\WebhookDelivery;

/**
 * The Webhook page's delivery log: every POST the hook received and what
 * became of it. "Why didn't my push deploy?" is answered here.
 */
class DeliveriesCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:deliveries
                            {--limit=20 : Rows to show}
                            {--json : Machine-readable output}';

    protected $description = 'Recent webhook deliveries and their outcome — the Webhook page log';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $deliveries = WebhookDelivery::query()->latest('id')->limit($limit)->get();

        if ($this->option('json')) {
            $this->json($deliveries->map(fn (WebhookDelivery $d) => [
                'id' => $d->id,
                'at' => $d->created_at?->toIso8601String(),
                'provider' => $d->provider,
                'server' => $d->server_name,
                'delivery_id' => $d->delivery_id,
                'event' => $d->event,
                'ref' => $d->ref,
                'sha' => $d->sha,
                'signature_valid' => (bool) $d->signature_valid,
                'matched_pipeline' => (bool) $d->matched_pipeline,
                'outcome' => $d->outcome,
                'detail' => $d->outcome_detail,
                'deployment_id' => $d->deployment_id,
            ])->values()->all());

            return self::SUCCESS;
        }

        if ($deliveries->isEmpty()) {
            $this->components->info('No deliveries yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'When', 'Provider', 'Event', 'Ref', 'SHA', 'Signature', 'Outcome', 'Deploy', 'Detail'],
            $deliveries->map(fn (WebhookDelivery $d) => [
                '#'.$d->id,
                $this->ago($d->created_at),
                $d->provider,
                $d->event ?: '—',
                $d->ref ? preg_replace('#^refs/heads/#', '', $d->ref) : '—',
                $this->shortSha($d->sha),
                $d->signature_valid ? 'ok' : 'bad',
                $d->outcome ?: '—',
                $d->deployment_id ? '#'.$d->deployment_id : '—',
                $this->truncate($d->outcome_detail, 50),
            ])->all()
        );

        return self::SUCCESS;
    }
}
