<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientEvent;
use App\Models\User;
use App\Services\AppointmentScheduler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PatientLifecycleController extends Controller
{
    /** Archive ou réactive la fiche avec justification ; refuse l’archivage si une opération reste ouverte. */
    public function update(Request $request, Patient $patient, AppointmentScheduler $scheduler)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['archived' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $scheduler->transaction(function () use ($request, $patient, $scheduler, $data) {
            // Same ordering as scheduling, clinical writes and billing: doctors, clinic, patient.
            $scheduler->lockDoctors(User::where('role', 'doctor')->pluck('id')->all());
            ClinicSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            abort_unless(User::findOrFail($request->user()->id)->role === 'admin' && $request->user()->fresh()->status === 'active', 403);
            $archived = (bool) $data['archived'];
            if ($archived === ($patient->archived_at !== null)) {
                throw ValidationException::withMessages(['reason' => 'La fiche a déjà cet état. Actualisez la page.']);
            }
            if ($archived && (Appointment::where('patient_id', $patient->id)->whereIn('status', Appointment::OPEN)->exists() || Invoice::where('patient_id', $patient->id)->whereIn('status', ['draft', 'issued', 'partially_paid'])->exists())) {
                throw ValidationException::withMessages(['reason' => 'Terminez ou annulez les rendez-vous ouverts et réglez ou annulez les factures ouvertes avant l’archivage.']);
            }
            $patient->archived_at = $archived ? now() : null;
            $patient->save();
            PatientEvent::create(['patient_id' => $patient->id, 'actor_id' => $request->user()->id, 'action' => $archived ? 'patient.archived' : 'patient.reactivated', 'reason' => trim($data['reason']), 'created_at' => now()]);
        });

        return back()->with('status', $data['archived'] ? 'Patient archivé. Les historiques sont conservés.' : 'Patient réactivé.');
    }
}
