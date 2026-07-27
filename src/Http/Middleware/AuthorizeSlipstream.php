<?php

namespace Slipstream\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Slipstream\Slipstream;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeSlipstream
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Slipstream::check($request)) {
            abort(403);
        }

        return $next($request);
    }
}
