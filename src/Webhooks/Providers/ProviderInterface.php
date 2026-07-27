<?php

namespace Slipstream\Webhooks\Providers;

use Illuminate\Http\Request;
use Slipstream\Webhooks\PushEvent;

interface ProviderInterface
{
    public function name(): string;

    /**
     * Whether this provider should handle the request (headers / path hint).
     */
    public function matches(Request $request): bool;

    public function parse(Request $request, string $rawBody, ?string $secret): PushEvent;
}
