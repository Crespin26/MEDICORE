<?php

use App\Http\Controllers\PatientController;
use App\Models\Patient;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/login')->name('home');
Route::middleware(['auth', 'can:manage-clinic'])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('dashboard', ['patientCount' => Patient::count()]))->name('dashboard');
    Route::resource('patients', PatientController::class)->only(['index', 'store', 'update']);
});
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
