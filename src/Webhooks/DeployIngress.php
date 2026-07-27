<?php

namespace Slipstream\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Slipstream\Deploy\Deployer;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\Deployment;
use Slipstream\Models\Pipeline;
use Slipstream\Models\Server;
use Slipstream\Models\WebhookDelivery;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;

/**
 * Shared path for provider webhooks and internal relay pings.
 */
class DeployIngress
{
    public function __construct(
        protected SettingsRepository $settings,
        protected ProcessDetacher $detacher,
        protected ProviderResolver $resolver,
        protected RelayFanout $relay,
    ) {}

    public function handle(Request $request): Response
    {
        $raw = $request->getContent();

        // Reject oversized bodies before parsing â€” the endpoint is unauthenticated.
        $maxBytes = (int) config('slipstream.webhook.max_body_bytes', 0);
        if ($maxBytes > 0 && strlen($raw) > $maxBytes) {
            WebhookDelivery::query()->create([
                'provider' => 'unknown',
                'server_name' => Slipstream::serverName(),
                'signature_valid' => false,
                'outcome' => 'rejected',
                'outcome_detail' => 'Body exceeds max_body_bytes ('.strlen($raw).' > '.$maxBytes.')',
            ]);

            return response('payload too large', 413);
        }

        $provider = $this->resolver->resolve($request);

        // Relay is internal fleet traffic and verifies against the fleet key;
        // external providers verify against the secret shared with the git host.
        // Keeping them distinct means a leaked webhook secret cannot forge a
        // relay push (which carries an arbitrary SHA and pipeline id).
        $secret = $provider->name() === 'relay'
            ? $this->settings->peerSecret()
            : $this->settings->webhookSecret();

        $event = $provider->parse($request, $raw, $secret);

        // Everything below writes parsed values into columns BEFORE the
        // signature is checked (a rejected delivery still has to be auditable),
        // so clamp them to their column sizes first â€” an oversized field would
        // otherwise raise SQLSTATE 22001 on MySQL/Postgres and turn an
        // unauthenticated POST into a 500.
        $deliveryId = $this->clamp($event->deliveryId, 255);
        $eventName = $this->clamp($event->event, 255);
        $ref = $this->clamp($event->ref, 255);
        $sha = $this->clamp($event->sha, 64);

        $pipeline = null;
        $matched = false;
        if ($event->signatureValid && is_string($event->ref)) {
            $pipeline = Pipeline::query()
                ->where('enabled', true)
                ->where('auto_deploy', true)
                ->get()
                ->first(fn (Pipeline $p) => $p->matchesRef($event->ref));
            $matched = $pipeline !== null;
        }

        // Relay may carry pipeline_id from the parent â€” only trust it once the
        // relay signature has verified (never query on unsigned input).
        if ($event->signatureValid && ! $pipeline && $event->provider === 'relay' && data_get($event->meta, 'pipeline_id')) {
            $pipeline = Pipeline::query()->find(data_get($event->meta, 'pipeline_id'));
            $matched = $pipeline !== null && $pipeline->enabled && $pipeline->auto_deploy;
        }

        $delivery = WebhookDelivery::query()->create([
            'provider' => $this->clamp($event->provider, 32),
            'server_name' => Slipstream::serverName(),
            'delivery_id' => $deliveryId,
            'event' => $eventName,
            'ref' => $ref,
            'sha' => $sha,
            'signature_valid' => $event->signatureValid,
            'matched_pipeline' => $matched,
            'payload_meta' => $this->clampMeta($event->meta),
        ]);

        if (! $event->signatureValid) {
            $delivery->update([
                'outcome' => 'rejected',
                'outcome_detail' => 'Invalid signature/token for provider '.$event->provider,
            ]);

            return response('invalid signature', 401);
        }

        // Idempotency / relay-replay guard: if THIS node already turned a
        // delivery with this (provider, delivery_id) into a deployment, don't do
        // it again. Scoped by server_name because every node shares one DB.
        if ($deliveryId && $this->isDuplicate($event->provider, $deliveryId, $delivery->id)) {
            $delivery->update([
                'outcome' => 'ignored',
                'outcome_detail' => 'Duplicate delivery â€” already processed on this node',
            ]);

            return response('duplicate', 200);
        }

        if ($event->isPing) {
            $delivery->update([
                'outcome' => 'accepted',
                'outcome_detail' => strtoupper($event->provider).' ping OK',
            ]);

            return response('pong', 200);
        }

        // Non-push events that weren't classified as ping.
        if ($event->provider === 'github' && $event->event !== 'push') {
            $delivery->update([
                'outcome' => 'ignored',
                'outcome_detail' => 'Unhandled event: '.$eventName,
            ]);

            return response('ignored', 200);
        }

        if ($event->provider === 'gitlab' && ! str_contains(strtolower((string) $event->event), 'push')) {
            $delivery->update([
                'outcome' => 'ignored',
                'outcome_detail' => 'Unhandled event: '.$eventName,
            ]);

            return response('ignored', 200);
        }

        if ($event->provider === 'bitbucket' && ! str_contains((string) $event->event, 'push')) {
            $delivery->update([
                'outcome' => 'ignored',
                'outcome_detail' => 'Unhandled event: '.$eventName,
            ]);

            return response('ignored', 200);
        }

        if (! $matched || ! $pipeline) {
            $delivery->update([
                'outcome' => 'ignored',
                'outcome_detail' => 'No enabled pipeline matched ref '.$ref,
            ]);

            return response('no matching pipeline', 200);
        }

        // Set enabled/paused explicitly on first registration â€” a firstOrCreate
        // create path leaves them null in memory (DB defaults aren't hydrated),
        // which would make the very first webhook to a new node look "disabled".
        $server = Server::query()->firstOrCreate(
            ['server_name' => Slipstream::serverName()],
            ['hostname' => gethostname() ?: null, 'enabled' => true, 'paused' => false]
        );

        // Fan-out to peers only on external providers (not on relay again).
        $relayResults = [];
        if ($event->provider !== 'relay' && $this->relay->enabled()) {
            $fields = $this->relay->payloadFrom($event, $pipeline->id);

            if (config('slipstream.webhook.relay.detach', true)) {
                // Persist the payload and hand fan-out to a detached process so
                // the provider gets a fast 200 even when peers are slow/down.
                $delivery->update([
                    'payload_meta' => array_merge($delivery->payload_meta ?? [], ['relay_pending' => $fields]),
                ]);
                try {
                    $this->detacher->spawnArtisan(['slipstream:relay', '--delivery='.$delivery->id]);
                    $relayResults = [['server' => '(detached)', 'ok' => true, 'status' => null, 'detail' => 'fan-out dispatched']];
                } catch (\Throwable $e) {
                    $relayResults = $this->relay->fanOutPayload($fields);
                }
            } else {
                $relayResults = $this->relay->fanOutPayload($fields);
            }
        }

        if ($server->paused || ! $server->enabled) {
            $detail = 'Server paused or disabled';
            if ($relayResults !== []) {
                $detail .= '; relayed to '.count($relayResults).' peer(s)';
            }
            $delivery->update([
                'outcome' => 'ignored',
                'outcome_detail' => $detail,
                'payload_meta' => array_merge($delivery->payload_meta ?? [], ['relay' => $relayResults]),
            ]);

            return response('server paused', 200);
        }

        if (! $pipeline->appliesToServer(Slipstream::serverName())) {
            $detail = 'Pipeline "'.$pipeline->name.'" does not include this server';
            if ($relayResults !== []) {
                $detail .= '; relayed to '.count($relayResults).' peer(s)';
            }
            $delivery->update([
                'outcome' => 'ignored',
                'outcome_detail' => $detail,
                'payload_meta' => array_merge($delivery->payload_meta ?? [], ['relay' => $relayResults]),
            ]);

            return response('server not in pipeline', 200);
        }

        if (! $event->sha) {
            $delivery->update([
                'outcome' => 'ignored',
                'outcome_detail' => 'No commit SHA (branch deleted?)',
            ]);

            return response('ignored', 200);
        }

        // Reject a malformed object id here rather than storing a truncated one:
        // Deployer refuses it anyway, and a clamped value could otherwise pass
        // the hex check downstream while pointing at nothing.
        if (! Deployer::isValidSha($event->sha)) {
            $delivery->update([
                'outcome' => 'rejected',
                'outcome_detail' => 'Commit SHA is not a valid hex object id',
            ]);

            return response('invalid sha', 422);
        }

        // Replay bound. A provider HMAC signs the body and nothing else — no
        // timestamp, and the delivery id we dedupe on rides in an UNSIGNED
        // header — so anyone holding one captured delivery could otherwise
        // replay it forever with a fresh delivery id. A replay always names a
        // commit this node already runs, so make webhook deploys idempotent per
        // commit: if the last finished deploy here is this SHA and it succeeded,
        // there is nothing to do. A genuine push carries a new SHA, and a
        // provider "redeliver" after a FAILED deploy still works because the
        // last deploy wasn't a success.
        if ($this->alreadyRunning($event->sha)) {
            $delivery->update([
                'outcome' => 'ignored',
                'outcome_detail' => 'Already deployed on this node: '.substr($event->sha, 0, 7),
            ]);

            return response('already deployed', 200);
        }

        $branch = $this->clamp($event->branch() ?: $pipeline->branch_pattern, 255);

        $deployment = Deployment::query()->create([
            'server_name' => Slipstream::serverName(),
            'sha' => $sha,
            'branch' => $branch,
            'commit_message' => $event->commitMessage ? mb_substr($event->commitMessage, 0, 255) : null,
            'commit_author' => $event->commitAuthor ? mb_substr($event->commitAuthor, 0, 255) : null,
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'status' => Deployment::STATUS_PENDING,
            'pipeline_id' => $pipeline->id,
        ]);

        $delivery->update([
            'deployment_id' => $deployment->id,
            // Merge into the current payload_meta (not $event->meta) so the
            // detached fan-out's relay_pending payload is preserved for RelayCommand.
            'payload_meta' => array_merge($delivery->payload_meta ?? [], ['relay' => $relayResults]),
        ]);

        try {
            $this->detacher->spawnArtisan([
                'slipstream:deploy',
                '--deployment='.$deployment->id,
            ]);
            $detail = 'Deploy process spawned';
            if ($relayResults !== []) {
                $ok = collect($relayResults)->where('ok', true)->count();
                $detail .= "; relayed to {$ok}/".count($relayResults).' peers';
            }
            $delivery->update([
                'outcome' => 'accepted',
                'outcome_detail' => $detail,
            ]);
        } catch (\Throwable $e) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'failure_reason' => 'Spawn failed: '.$e->getMessage(),
                'finished_at' => now(),
            ]);
            $delivery->update([
                'outcome' => 'spawn_failed',
                'outcome_detail' => $e->getMessage(),
            ]);

            return response('spawn failed', 500);
        }

        return response('accepted', 200);
    }

    /**
     * Is this SHA what the node is already serving after a successful deploy?
     *
     * Deliberately keyed on the LAST finished deployment rather than "any
     * successful deploy of this SHA": after a rollback the newest row is the
     * rollback (a different SHA), so rolling forward by re-sending the webhook
     * still works.
     */
    protected function alreadyRunning(?string $sha): bool
    {
        if (! is_string($sha) || $sha === '') {
            return false;
        }

        if (filter_var(config('slipstream.webhook.allow_redeploy_same_sha', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $last = Deployment::query()
            ->where('server_name', Slipstream::serverName())
            ->whereIn('status', [
                Deployment::STATUS_DEPLOYED,
                Deployment::STATUS_FAILED,
                Deployment::STATUS_ROLLED_BACK,
            ])
            ->latest('id')
            ->first();

        return $last !== null
            && $last->status === Deployment::STATUS_DEPLOYED
            && is_string($last->sha)
            && strtolower($last->sha) === strtolower($sha);
    }

    /**
     * Trim a value parsed from an unverified body to what its column holds.
     * Returns null for anything that isn't a non-empty string so a hostile
     * payload can't push an array/object into a string column either.
     */
    protected function clamp(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    /**
     * Same treatment for the stored payload_meta: string values are bounded,
     * scalars (e.g. pipeline_id) pass through, anything else is dropped.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function clampMeta(array $meta): array
    {
        $out = [];

        foreach ($meta as $key => $value) {
            if (is_string($value)) {
                $out[$key] = $this->clamp($value, 255);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Has this node already turned a delivery with the same (provider,
     * delivery_id) into a deployment? Guards GitHub redelivery and relay replay.
     */
    protected function isDuplicate(string $provider, string $deliveryId, int $currentId): bool
    {
        return WebhookDelivery::query()
            ->where('provider', $provider)
            ->where('server_name', Slipstream::serverName())
            ->where('delivery_id', $deliveryId)
            ->where('id', '<', $currentId)
            ->whereNotNull('deployment_id')
            ->exists();
    }
}
