<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Slipstream\Fleet\DeployQueue;
use Slipstream\Models\Deployment;
use Slipstream\Models\Server;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\PeerSignature;

/**
 * Fan out content-free "check your deploy queue" nudges to peers that have a
 * queued fleet action and a base_url. Spawned detached by DeployQueue::queue()
 * so the dashboard action returns fast; peers without a base_url just wait for
 * their heartbeat.
 */
class DeployNudgeCommand extends Command
{
    protected $signature = 'slipstream:deploy-nudge {--batch= : Only nudge servers targeted by this fleet batch}';

    protected $description = 'Nudge peers to run a queued fleet deploy/rollback (detached from the dashboard request)';

    public function handle(SettingsRepository $settings): int
    {
        // Fleet key, not the provider-shared webhook secret — see
        // SettingsRepository::peerSecret().
        $secret = $settings->peerSecret();
        if (! $secret) {
            $this->warn('No fleet signing key configured — cannot sign nudges.');

            return self::SUCCESS;
        }

        $self = Slipstream::serverName();
        $batch = $this->option('batch');

        // Only servers that actually have a queued row (batch-filtered when
        // given), excluding this node.
        $query = Deployment::query()
            ->where('status', Deployment::STATUS_QUEUED)
            ->where('server_name', '!=', $self);
        if ($batch) {
            $query->where('meta->fleet_batch', $batch);
        }
        $targetNames = $query->distinct()->pluck('server_name')->all();

        if ($targetNames === []) {
            return self::SUCCESS;
        }

        $peers = Server::query()
            ->where('enabled', true)
            ->whereIn('server_name', $targetNames)
            ->whereNotNull('base_url')
            ->where('base_url', '!=', '')
            ->get();

        $path = trim(Slipstream::path(), '/');
        $timeout = (int) config('slipstream.webhook.relay.timeout', 5);

        $body = json_encode(['batch' => $batch ?: null, 'from_server' => $self], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = PeerSignature::sign(DeployQueue::SIGNING_VERSION, $timestamp, $body, $secret);

        $sent = 0;
        foreach ($peers as $peer) {
            $url = rtrim($peer->base_url, '/').'/'.$path.'/hooks/deploy-sync';

            try {
                Http::timeout($timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-Slipstream-Deploy-Sync' => '1',
                        'X-Slipstream-Signature' => $signature,
                        'X-Slipstream-Timestamp' => $timestamp,
                    ])
                    ->withBody($body, 'application/json')
                    ->post($url);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Slipstream deploy-sync nudge to '.$peer->server_name.' failed: '.$e->getMessage());
            }
        }

        $this->info("Deploy-sync nudge sent to {$sent}/".count($peers).' peer(s).');

        return self::SUCCESS;
    }
}
