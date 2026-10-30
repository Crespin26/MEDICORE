<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Enregistre la politique de mot de passe, les limites API et les permissions des rôles.
     */
    public function boot(): void
    {
        // One policy for invitations, password resets and authenticated changes.
        Password::defaults(fn () => Password::min(12));
        RateLimiter::for('mobile-login', function ($request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by(hash('sha256', (is_string($email) ? strtolower($email) : 'invalid').'|'.$request->ip()));
        });
        RateLimiter::for('mobile-api', fn ($request) => Limit::perMinute(120)->by((string) ($request->user()?->id ?? $request->ip())));
        foreach (Role::permissionNames() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
