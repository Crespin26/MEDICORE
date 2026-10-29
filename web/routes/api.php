<?php

use App\Http\Controllers\Api\MobileSessionController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\PatientController;
use App\Http\Middleware\EnsureMobileAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('session', [MobileSessionController::class, 'store'])->middleware(['throttle:mobile-login', 'throttle:10,1']);
    Route::middleware(['auth:sanctum', EnsureMobileAccess::class, 'throttle:mobile-api'])->group(function () {
        Route::get('me', [MobileSessionController::class, 'show']);
        Route::delete('session', [MobileSessionController::class, 'destroy']);
        Route::get('patients', [PatientController::class, 'index'])->middleware('can:patients.view');
        Route::get('patients/{patient}', [PatientController::class, 'show'])->middleware('can:patients.view');
        Route::post('patients', [PatientController::class, 'store'])->middleware('can:patients.write');
        Route::patch('patients/{patient}', [PatientController::class, 'update'])->middleware('can:patients.write');
        Route::get('appointments', [AppointmentController::class, 'index'])->middleware('can:appointments.view');
        Route::post('appointments', [AppointmentController::class, 'store'])->middleware('can:appointments.write');
        Route::patch('appointments/{appointment}', [AppointmentController::class, 'update'])->middleware('can:appointments.write');
        Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'status'])->middleware('can:appointments.status');
    });
});
