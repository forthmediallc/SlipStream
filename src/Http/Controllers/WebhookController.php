<?php

namespace Slipstream\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Slipstream\Webhooks\DeployIngress;
use Slipstream\Webhooks\DeploySyncIngress;
use Slipstream\Webhooks\EnvSyncIngress;

class WebhookController extends Controller
{
    /**
     * Provider webhooks (GitHub / GitLab / Bitbucket) and manual posts.
     */
    public function deploy(Request $request, DeployIngress $ingress): Response
    {
        return $ingress->handle($request);
    }

    /**
     * Internal peer fan-out target (HMAC via X-Slipstream-Signature).
     */
    public function relay(Request $request, DeployIngress $ingress): Response
    {
        return $ingress->handle($request);
    }

    /**
     * Signed env-sync nudge from a peer (HMAC via X-Slipstream-Signature).
     */
    public function envSync(Request $request, EnvSyncIngress $ingress): Response
    {
        return $ingress->handle($request);
    }

    /**
     * Signed deploy-queue nudge from a peer (HMAC via X-Slipstream-Signature).
     */
    public function deploySync(Request $request, DeploySyncIngress $ingress): Response
    {
        return $ingress->handle($request);
    }
}
