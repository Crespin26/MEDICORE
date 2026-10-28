<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\DoctorAvailabilityController;
use App\Http\Controllers\MedicalDocumentController;
use App\Http\Controllers\MedicalRecordController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\StaffController;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/login')->name('home');
Route::middleware('auth')->group(function () {
    Route::get('dashboard', fn (Request $request) => Inertia::render('dashboard', [
        'patientCount' => $request->user()->can('patients.view') ? Patient::count() : null,
    ]))->middleware('can:dashboard.view')->name('dashboard');
    Route::get('patients', [PatientController::class, 'index'])->middleware('can:patients.view')->name('patients.index');
    Route::get('patients/{patient}', [PatientController::class, 'show'])->middleware('can:patients.view')->name('patients.show');
    Route::post('patients', [PatientController::class, 'store'])->middleware('can:patients.write')->name('patients.store');
    Route::patch('patients/{patient}', [PatientController::class, 'update'])->middleware('can:patients.write')->name('patients.update');
    Route::get('appointments', [AppointmentController::class, 'index'])->middleware('can:appointments.view')->name('appointments.index');
    Route::get('appointments/patients', [AppointmentController::class, 'patients'])->middleware('can:appointments.write')->name('appointments.patients');
    Route::post('appointments', [AppointmentController::class, 'store'])->middleware('can:appointments.write')->name('appointments.store');
    Route::patch('appointments/{appointment}', [AppointmentController::class, 'update'])->middleware('can:appointments.write')->name('appointments.update');
    Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'status'])->middleware('can:appointments.status')->name('appointments.status');
    Route::middleware('can:availabilities.manage')->group(function () {
        Route::get('availabilities', [DoctorAvailabilityController::class, 'index'])->name('availabilities.index');
        Route::post('availabilities', [DoctorAvailabilityController::class, 'store'])->name('availabilities.store');
        Route::delete('availabilities/{availability}', [DoctorAvailabilityController::class, 'destroy'])->name('availabilities.destroy');
    });
    Route::middleware('can:consultations.view')->group(function () {
        Route::get('consultations', [ConsultationController::class, 'index'])->name('consultations.index');
        Route::get('consultations/{consultation}', [ConsultationController::class, 'show'])->name('consultations.show');
    });
    Route::middleware('can:consultations.write')->group(function () {
        Route::get('patients/{patient}/consultations/create', [ConsultationController::class, 'create'])->name('consultations.create');
        Route::post('patients/{patient}/consultations', [ConsultationController::class, 'store'])->name('consultations.store');
        Route::patch('consultations/{consultation}', [ConsultationController::class, 'update'])->name('consultations.update');
    });
    Route::middleware('can:medical-record.view')->group(function () {
        Route::get('patients/{patient}/medical-record', [MedicalRecordController::class, 'show'])->name('medical-record.show');
        Route::get('patients/{patient}/medical-record/audit', [MedicalRecordController::class, 'audit'])->name('medical-record.audit');
        Route::get('patients/{patient}/medical-entries/{entry}', [MedicalRecordController::class, 'entry'])->name('medical-entries.show');
        Route::get('patients/{patient}/medical-documents/{document}/download', [MedicalDocumentController::class, 'download'])->name('medical-documents.download');
    });
    Route::middleware('can:medical-record.write')->group(function () {
        Route::post('patients/{patient}/medical-entries', [MedicalRecordController::class, 'store'])->name('medical-entries.store');
        Route::patch('patients/{patient}/medical-entries/{entry}', [MedicalRecordController::class, 'update'])->name('medical-entries.update');
        Route::post('patients/{patient}/medical-documents', [MedicalDocumentController::class, 'store'])->name('medical-documents.store');
    });
    Route::middleware('can:billing.view')->group(function () {
        Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
        Route::get('billing/create', [BillingController::class, 'create'])->name('billing.create');
        Route::get('billing/patients', [BillingController::class, 'patients'])->name('billing.patients');
        Route::get('billing/invoices/{invoice}', [BillingController::class, 'show'])->name('billing.show');
        Route::get('billing/invoices/{invoice}/print', [BillingController::class, 'print'])->name('billing.print');
        Route::get('billing/receipts/{payment}', [BillingController::class, 'receipt'])->name('billing.receipt');
    });
    Route::middleware('can:billing.manage')->group(function () {
        Route::post('billing/invoices', [BillingController::class, 'store'])->name('billing.store');
        Route::patch('billing/invoices/{invoice}', [BillingController::class, 'update'])->name('billing.update');
        Route::post('billing/invoices/{invoice}/issue', [BillingController::class, 'issue'])->name('billing.issue');
    });
    Route::post('billing/invoices/{invoice}/payments', [BillingController::class, 'pay'])->middleware('can:billing.pay')->name('billing.pay');
    Route::post('billing/invoices/{invoice}/cancel', [BillingController::class, 'cancel'])->middleware('can:billing.reverse')->name('billing.cancel');
    Route::post('billing/payments/{payment}/void', [BillingController::class, 'void'])->middleware('can:billing.reverse')->name('billing.void');
    Route::middleware('can:catalog.manage')->group(function () {
        Route::get('billing/catalog', [BillingController::class, 'catalog'])->name('billing.catalog');
        Route::post('billing/catalog', [BillingController::class, 'saveService'])->name('billing.catalog.store');
        Route::patch('billing/catalog/{service}', [BillingController::class, 'saveService'])->name('billing.catalog.update');
    });
    Route::get('personnel/me', [PersonnelController::class, 'own'])->middleware('can:personnel.self')->name('personnel.own');
    Route::middleware('can:personnel.manage')->group(function () {
        Route::get('personnel', [PersonnelController::class, 'index'])->name('personnel.index');
        Route::get('personnel/{user}', [PersonnelController::class, 'show'])->name('personnel.show');
        Route::patch('personnel/{user}', [PersonnelController::class, 'update'])->name('personnel.update');
        Route::post('personnel/{user}/absences', [PersonnelController::class, 'absence'])->name('personnel.absences.store');
        Route::post('personnel/{user}/absences/{absence}/cancel', [PersonnelController::class, 'cancel'])->name('personnel.absences.cancel');
    });
    Route::middleware('can:users.manage')->group(function () {
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->middleware('throttle:10,1')->name('staff.store');
        Route::patch('staff/{user}', [StaffController::class, 'update'])->name('staff.update');
        Route::post('staff/{user}/invitation', [StaffController::class, 'invite'])->middleware('throttle:10,1')->name('staff.invite');
    });
});
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
