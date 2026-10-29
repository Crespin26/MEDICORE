<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('mobile-login', function ($request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by(hash('sha256', (is_string($email) ? strtolower($email) : 'invalid').'|'.$request->ip()));
        });
        RateLimiter::for('mobile-api', fn ($request) => Limit::perMinute(120)->by((string) ($request->user()?->id ?? $request->ip())));
        foreach (['dashboard.view', 'patients.view', 'patients.write', 'users.manage', 'appointments.view', 'appointments.write', 'appointments.status', 'availabilities.manage', 'consultations.view', 'consultations.write', 'medical-record.view', 'medical-record.write', 'billing.view', 'billing.manage', 'billing.pay', 'billing.reverse', 'catalog.manage', 'personnel.manage', 'personnel.self', 'administration.manage', 'administration.export'] as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
        //
    }
}
