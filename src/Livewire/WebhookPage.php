<?php

namespace Slipstream\Livewire;

use Illuminate\Http\Request;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use Slipstream\Models\Server;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;
use Slipstream\Webhooks\DeployIngress;

class WebhookPage extends SlipstreamComponent
{
    use WithPagination;

    #[Locked]
    public bool $revealSecret = false;

    #[Locked]
    public ?string $freshSecret = null;

    public bool $relay_enabled = false;

    public function mount(SettingsRepository $settings): void
    {
        $settings->ensureWebhookSecret();
        $this->relay_enabled = filter_var($settings->get('relay_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function reveal(SettingsRepository $settings): void
    {
        $this->revealSecret = true;
        $this->freshSecret = $settings->webhookSecret();
    }

    public function rotate(SettingsRepository $settings): void
    {
        $this->freshSecret = $settings->rotateWebhookSecret();
        $this->revealSecret = true;
        Activity::log('webhook.secret_rotated');
        session()->flash('slipstream_status', 'Webhook secret rotated. Update GitHub / GitLab / Bitbucket now.');
    }

    /**
     * Give the fleet its own signing key so the value pasted into GitHub /
     * GitLab / Bitbucket stops doubling as the trust root for relay pushes and
     * env-sync nudges. Never revealed: it stays in the shared DB and is read
     * straight from there by every node.
     */
    public function rotatePeerSecret(SettingsRepository $settings): void
    {
        $separated = $settings->hasSeparatePeerSecret();
        $settings->rotatePeerSecret();

        Activity::log($separated ? 'webhook.fleet_key_rotated' : 'webhook.fleet_key_separated');
        session()->flash(
            'slipstream_status',
            $separated
                ? 'Fleet key rotated. Every node reads it from the shared database — no provider changes needed.'
                : 'Fleet key separated from the webhook secret. Make sure every node runs this Slipstream version, '
                    .'or peers still signing with the old shared secret will be rejected.'
        );
    }

    /**
     * Send a locally-signed ping through the real ingress path to prove the
     * secret + signature verification are wired correctly end to end.
     */
    public function sendTestPing(SettingsRepository $settings, DeployIngress $ingress): void
    {
        $secret = $settings->webhookSecret();
        if (! $secret) {
            session()->flash('slipstream_error', 'No webhook secret set.');

            return;
        }

        $body = json_encode(['zen' => 'Slipstream test ping', 'hook_id' => 0], JSON_THROW_ON_ERROR);
        $request = Request::create(
            url('/'.Slipstream::path().'/hooks/deploy'),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body
        );
        $request->headers->set('X-GitHub-Event', 'ping');
        $request->headers->set('X-GitHub-Delivery', 'test-'.bin2hex(random_bytes(6)));
        $request->headers->set('X-Hub-Signature-256', 'sha256='.hash_hmac('sha256', $body, $secret));

        $response = $ingress->handle($request);

        Activity::log('webhook.test_ping', ['status' => $response->getStatusCode()]);

        if ($response->getStatusCode() === 200) {
            session()->flash('slipstream_status', 'Test ping verified OK (signature accepted).');
        } else {
            session()->flash('slipstream_error', 'Test ping failed: '.$response->getStatusCode().' '.$response->getContent());
        }
    }

    public function saveRelay(SettingsRepository $settings): void
    {
        $settings->set('relay_enabled', $this->relay_enabled ? '1' : '0');
        Activity::log('webhook.relay_'.($this->relay_enabled ? 'enabled' : 'disabled'));
        session()->flash(
            'slipstream_status',
            $this->relay_enabled
                ? 'Relay mode on — this node will fan out to peers with a base_url.'
                : 'Relay mode off — each server needs its own provider webhook.'
        );
    }

    public function render(SettingsRepository $settings)
    {
        $path = Slipstream::path();
        $url = url('/'.$path.'/hooks/deploy');
        $relayUrl = url('/'.$path.'/hooks/relay');

        $peers = Server::query()
            ->where('server_name', '!=', Slipstream::serverName())
            ->orderBy('server_name')
            ->get();

        return view('slipstream::livewire.webhook-page', [
            'endpoint' => $url,
            'relayEndpoint' => $relayUrl,
            'secret' => $this->revealSecret ? ($this->freshSecret ?: $settings->webhookSecret()) : null,
            'peerSeparated' => $settings->hasSeparatePeerSecret(),
            'deliveries' => WebhookDelivery::query()->latest('id')->paginate(25),
            'serverName' => Slipstream::serverName(),
            'peers' => $peers,
        ]);
    }
}
