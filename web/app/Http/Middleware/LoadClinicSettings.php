<?php

namespace App\Http\Middleware;

use App\Models\ClinicSetting;
use Closure;
use Illuminate\Http\Request;

class LoadClinicSettings
{
    public function handle(Request $request, Closure $next)
    {
        $previous = config('clinic');
        $setting = ClinicSetting::findOrFail(1);
        config(['clinic.name' => $setting->name, 'clinic.phone' => $setting->phone, 'clinic.email' => $setting->email, 'clinic.address' => $setting->address, 'clinic.currency' => $setting->currency ?? config('clinic.currency'), 'clinic.opening_hours' => $setting->opening_hours ?? []]);
        try {
            return $next($request);
        } finally {
            config(['clinic' => $previous]);
        }
    }
}
