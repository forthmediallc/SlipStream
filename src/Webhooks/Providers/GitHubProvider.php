<?php

namespace Slipstream\Webhooks\Providers;

use Illuminate\Http\Request;
use Slipstream\Webhooks\PushEvent;

class GitHubProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'github';
    }

    public function matches(Request $request): bool
    {
        return $request->hasHeader('X-GitHub-Event')
            || $request->hasHeader('X-Hub-Signature-256')
            || $request->hasHeader('X-GitHub-Delivery');
    }

    public function parse(Request $request, string $rawBody, ?string $secret): PushEvent
    {
        $header = (string) $request->header('X-Hub-Signature-256', '');
        $valid = $this->verify($rawBody, (string) $secret, $header);

        $payload = json_decode($rawBody, true) ?: [];
        $ref = is_array($payload) ? ($payload['ref'] ?? null) : null;
        $sha = data_get($payload, 'after') ?: data_get($payload, 'head_commit.id');
        $event = $request->header('X-GitHub-Event', 'push');

        if (is_string($sha) && $sha === '0000000000000000000000000000000000000000') {
            $sha = null;
        }

        return new PushEvent(
            provider: $this->name(),
            event: is_string($event) ? $event : 'push',
            deliveryId: $request->header('X-GitHub-Delivery'),
            ref: is_string($ref) ? $ref : null,
            sha: is_string($sha) ? $sha : null,
            commitMessage: is_string($m = data_get($payload, 'head_commit.message')) ? $m : null,
            commitAuthor: is_string($a = data_get($payload, 'head_commit.author.name')) ? $a : null,
            signatureValid: $valid,
            isPing: $event === 'ping',
            meta: [
                'repository' => data_get($payload, 'repository.full_name'),
                'pusher' => data_get($payload, 'pusher.name'),
            ],
        );
    }

    protected function verify(string $payload, string $secret, string $header): bool
    {
        if ($secret === '' || $header === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(substr($header, 7), hash_hmac('sha256', $payload, $secret));
    }
}
