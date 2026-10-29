<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LoadClinicSettings;
use App\Http\Middleware\PrivateResponses;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(append: [LoadClinicSettings::class, PrivateResponses::class]);
        $middleware->web(append: [
            LoadClinicSettings::class,
            PrivateResponses::class,
            EnsureAccountActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response) {
            if (request()->is('api/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
            }

            return $response;
        });
        $exceptions->dontFlash(['reason', 'distinct_reason', 'observations', 'report', 'vitals', 'correction_reason', 'label', 'details', 'title', 'file']);
        //
    })->create();
