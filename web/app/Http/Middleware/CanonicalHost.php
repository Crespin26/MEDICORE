<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalHost
{
    /** Refuse les hôtes forgés en production avant toute génération de lien d’invitation ou de réinitialisation. */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction()) {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);
            abort_unless(is_string($host) && strtolower($request->getHost()) === strtolower($host), 400);
        }

        return $next($request);
    }
}
