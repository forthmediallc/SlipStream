<?php

namespace Slipstream\Webhooks\Providers;

use Illuminate\Http\Request;
use Slipstream\Webhooks\PushEvent;

/**
 * Bitbucket Cloud push webhooks (X-Event-Key + optional X-Hub-Signature).
 */
class BitbucketProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'bitbucket';
    }

    public function matches(Request $request): bool
    {
        return $request->hasHeader('X-Event-Key')
            || $request->hasHeader('X-Hook-UUID')
            || str_contains(strtolower((string) $request->header('User-Agent')), 'bitbucket');
    }

    public function parse(Request $request, string $rawBody, ?string $secret): PushEvent
    {
        $header = (string) $request->header('X-Hub-Signature', '');
        // Bitbucket may omit signature if secret not configured — require secret when we have one.
        $valid = $this->verify($rawBody, (string) $secret, $header);

        $payload = json_decode($rawBody, true) ?: [];
        $event = $request->header('X-Event-Key', 'repo:push');

        $change = data_get($payload, 'push.changes.0');
        $new = data_get($change, 'new');
        $branch = data_get($new, 'name');
        $sha = data_get($new, 'target.hash');
        $message = data_get($new, 'target.message');
        $author = data_get($new, 'target.author.user.display_name')
            ?: data_get($new, 'target.author.raw');

        $ref = is_string($branch) ? 'refs/heads/'.$branch : null;

        $isPing = str_contains((string) $event, 'diagnostic')
            || $event === 'repo:updated' && $change === null;

        return new PushEvent(
            provider: $this->name(),
            event: is_string($event) ? $event : 'repo:push',
            deliveryId: $request->header('X-Request-UUID') ?: $request->header('X-Hook-UUID'),
            ref: $ref,
            sha: is_string($sha) ? $sha : null,
            commitMessage: is_string($message) ? $message : null,
            commitAuthor: is_string($author) ? $author : null,
            signatureValid: $valid,
            isPing: $isPing || ! str_contains((string) $event, 'push'),
            meta: [
                'repository' => data_get($payload, 'repository.full_name'),
                'pusher' => data_get($payload, 'actor.display_name'),
            ],
        );
    }

    protected function verify(string $payload, string $secret, string $header): bool
    {
        if ($secret === '') {
            return false;
        }

        // Prefer signed webhooks; accept sha256= form.
        if ($header !== '' && str_starts_with($header, 'sha256=')) {
            return hash_equals(substr($header, 7), hash_hmac('sha256', $payload, $secret));
        }

        // Some Bitbucket setups send raw hex.
        if ($header !== '' && ! str_contains($header, '=')) {
            return hash_equals($header, hash_hmac('sha256', $payload, $secret));
        }

        // If secret configured but no signature header, reject.
        return false;
    }
}
