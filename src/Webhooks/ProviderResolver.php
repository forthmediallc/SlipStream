<?php

namespace Slipstream\Webhooks;

use Illuminate\Http\Request;
use Slipstream\Webhooks\Providers\BitbucketProvider;
use Slipstream\Webhooks\Providers\GitHubProvider;
use Slipstream\Webhooks\Providers\GitLabProvider;
use Slipstream\Webhooks\Providers\ProviderInterface;
use Slipstream\Webhooks\Providers\RelayProvider;

class ProviderResolver
{
    /**
     * @return list<ProviderInterface>
     */
    public function all(): array
    {
        return [
            new RelayProvider,
            new GitHubProvider,
            new GitLabProvider,
            new BitbucketProvider,
        ];
    }

    public function resolve(Request $request): ProviderInterface
    {
        foreach ($this->all() as $provider) {
            if ($provider->matches($request)) {
                return $provider;
            }
        }

        // Default to GitHub-shaped parse for generic POSTs / manual tests.
        return new GitHubProvider;
    }
}
