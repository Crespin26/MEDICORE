<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateResponses
{
    /** Empêche la mise en cache des réponses privées et limite les métadonnées transmises au navigateur. */
    public function handle(Request $request, Closure $next): Response
    {
        return self::secure($next($request));
    }

    /** Applique les mêmes protections aux réponses normales et aux erreurs, sans bloquer les scripts Inertia. */
    public static function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'; object-src 'none'; base-uri 'self'");

        return $response;
    }
}
