<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Slipstream;
use Slipstream\Webhooks\WebhookSourceRanges;

/**
 * The provider hook ranges behind the github / bitbucket keywords of
 * SLIPSTREAM_WEBHOOK_ALLOWED_IPS: what is stored, and a download on demand.
 * The heartbeat downloads them on its own schedule; this is for the first
 * time, and for looking.
 */
class WebhookRangesCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:webhook-ranges
                            {action=show : show | refresh}
                            {keyword? : github or bitbucket (refresh; default: every keyword in SLIPSTREAM_WEBHOOK_ALLOWED_IPS)}
                            {--json : Machine-readable output (show)}';

    protected $description = 'Show or download the git hosts\' published webhook ranges behind the allow-list keywords';

    public function handle(WebhookSourceRanges $ranges): int
    {
        return match ($this->argument('action')) {
            'show' => $this->show($ranges),
            'refresh' => $this->refresh($ranges),
            default => $this->unknownAction(),
        };
    }

    protected function show(WebhookSourceRanges $ranges): int
    {
        $configured = Slipstream::webhookAllowedIps();
        $resolved = $ranges->resolve($configured);

        if ($this->option('json')) {
            $this->json($resolved + ['stored' => $ranges->stored()]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Allowed sources', $configured === [] ? 'any (the signature is still verified)' : implode(', ', $configured));

        foreach ($resolved['keywords'] as $keyword) {
            $this->components->twoColumnDetail($keyword, $ranges->describe($keyword));
        }
        foreach ($resolved['unknown'] as $entry) {
            $this->components->error("\"{$entry}\" is neither an address nor a provider keyword (".implode(', ', WebhookSourceRanges::PROVIDERS).'); it matches nothing.');
        }
        if (Slipstream::pollMode()) {
            $this->components->warn('Poll mode: there is no webhook endpoint, so the list is not in use.');
        }

        return self::SUCCESS;
    }

    protected function refresh(WebhookSourceRanges $ranges): int
    {
        $keyword = $this->argument('keyword');
        $keywords = $keyword !== null
            ? [strtolower((string) $keyword)]
            : WebhookSourceRanges::classify(Slipstream::webhookAllowedIps())['keywords'];

        if ($keywords === []) {
            $this->components->error('No provider keyword in SLIPSTREAM_WEBHOOK_ALLOWED_IPS. Name one: slipstream:webhook-ranges refresh github');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($keywords as $name) {
            if (! WebhookSourceRanges::isKeyword($name)) {
                $this->components->error("\"{$name}\" is not a provider keyword; one of ".implode(', ', WebhookSourceRanges::PROVIDERS).'.');
                $failed++;

                continue;
            }

            $result = $ranges->refresh($name, 'cli');
            $this->components->twoColumnDetail($name, $result['detail']);
            $failed += $result['ok'] ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function unknownAction(): int
    {
        $this->components->error('Unknown action "'.$this->argument('action').'". Use: show, refresh.');

        return self::FAILURE;
    }
}
