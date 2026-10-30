<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Explicit proxy configuration, read at request time after Laravel has loaded its config. */
class TrustProxies
{
    /** Reset proxy state per request and trust forwarded headers only from configured peers. */
    public function handle(Request $request, Closure $next): Response
    {
        $request::setTrustedProxies(
            config('app.trusted_proxies', []),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        return $next($request);
    }
}
