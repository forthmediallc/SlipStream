<?php

namespace Slipstream\Webhooks\Providers;

use Illuminate\Http\Request;
use Slipstream\Webhooks\PushEvent;

/**
 * GitLab uses a shared token header (X-Gitlab-Token), not HMAC body signing.
 */
class GitLabProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'gitlab';
    }

    public function matches(Request $request): bool
    {
        return $request->hasHeader('X-Gitlab-Event')
            || $request->hasHeader('X-Gitlab-Token');
    }

    public function parse(Request $request, string $rawBody, ?string $secret): PushEvent
    {
        $token = (string) $request->header('X-Gitlab-Token', '');
        $valid = is_string($secret) && $secret !== '' && hash_equals($secret, $token);

        $payload = json_decode($rawBody, true) ?: [];
        $event = $request->header('X-Gitlab-Event', 'Push Hook');
        $ref = data_get($payload, 'ref');
        $sha = data_get($payload, 'checkout_sha')
            ?: data_get($payload, 'after')
            ?: data_get($payload, 'commits.0.id');

        $message = data_get($payload, 'commits.0.message');
        $author = data_get($payload, 'user_name')
            ?: data_get($payload, 'commits.0.author.name');

        $isPing = str_contains(strtolower((string) $event), 'ping')
            || (is_array($payload) && $payload === []);

        return new PushEvent(
            provider: $this->name(),
            event: is_string($event) ? $event : 'Push Hook',
            deliveryId: $request->header('X-Gitlab-Event-UUID') ?: $request->header('X-Request-Id'),
            ref: is_string($ref) ? $ref : null,
            sha: is_string($sha) && $sha !== '' ? $sha : null,
            commitMessage: is_string($message) ? $message : null,
            commitAuthor: is_string($author) ? $author : null,
            signatureValid: $valid,
            isPing: $isPing,
            meta: [
                'repository' => data_get($payload, 'project.path_with_namespace')
                    ?: data_get($payload, 'repository.name'),
                'pusher' => data_get($payload, 'user_username') ?: data_get($payload, 'user_name'),
            ],
        );
    }
}
