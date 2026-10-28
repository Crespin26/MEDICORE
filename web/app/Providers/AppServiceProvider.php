<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
        foreach (['dashboard.view', 'patients.view', 'patients.write', 'users.manage', 'appointments.view', 'appointments.write', 'appointments.status', 'availabilities.manage', 'consultations.view', 'consultations.write', 'medical-record.view', 'medical-record.write', 'billing.view', 'billing.manage', 'billing.pay', 'billing.reverse', 'catalog.manage'] as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
        //
    }
}
