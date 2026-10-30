<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileAccess
{
    /** Exige un vrai jeton mobile et un compte actif ; refuse une authentification par cookie seule. */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $request->bearerToken() || ! $token instanceof PersonalAccessToken || ! $token->can('mobile')) {
            abort(401, 'Authentification mobile requise.');
        }
        if ($user->status !== 'active' || Role::tryFrom($user->role) === null) {
            $user->tokens()->delete();
            abort(401, 'Session révoquée.');
        }

        return $next($request);
    }
}
