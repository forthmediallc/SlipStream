<?php

namespace Slipstream\Webhooks;

class PushEvent
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $event,
        public readonly ?string $deliveryId,
        public readonly ?string $ref,
        public readonly ?string $sha,
        public readonly ?string $commitMessage,
        public readonly ?string $commitAuthor,
        public readonly bool $signatureValid,
        public readonly bool $isPing,
        public readonly array $meta = [],
    ) {}

    public function branch(): ?string
    {
        if (! is_string($this->ref)) {
            return null;
        }

        if (str_starts_with($this->ref, 'refs/heads/')) {
            return substr($this->ref, strlen('refs/heads/'));
        }

        // GitLab sometimes uses refs/heads/… already; bare branch names rare.
        if (str_starts_with($this->ref, 'refs/tags/')) {
            return null;
        }

        return $this->ref;
    }
}
