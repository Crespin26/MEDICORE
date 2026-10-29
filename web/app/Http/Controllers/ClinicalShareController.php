<?php

namespace App\Http\Controllers;

use App\Models\ClinicalShare;
use App\Models\Patient;
use App\Models\PatientEvent;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\MedicalAudit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClinicalShareController extends Controller
{
    public function store(Request $request, Patient $patient, AppointmentScheduler $scheduler)
    {
        $data = $request->validate(['viewer_id' => ['required', 'integer', 'exists:users,id'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $scheduler->transaction(function () use ($request, $patient, $scheduler, $data) {
            $scheduler->lockDoctors([$request->user()->id, (int) $data['viewer_id']]);
            $viewer = User::findOrFail($data['viewer_id']);
            abort_unless(User::findOrFail($request->user()->id)->hasPermission('medical-record.write'), 403);
            if ($viewer->id === $request->user()->id || $viewer->role !== 'doctor' || $viewer->status !== 'active') {
                throw ValidationException::withMessages(['viewer_id' => 'Choisissez un autre médecin actif.']);
            }
            $share = ClinicalShare::updateOrCreate(['patient_id' => $patient->id, 'owner_id' => $request->user()->id, 'viewer_id' => $viewer->id], ['reason' => trim($data['reason']), 'revoked_at' => null]);
            PatientEvent::create(['patient_id' => $patient->id, 'actor_id' => $request->user()->id, 'action' => 'sharing.granted', 'reason' => trim($data['reason']), 'metadata' => ['share_id' => $share->id, 'viewer_id' => $viewer->id], 'created_at' => now()]);
            MedicalAudit::record($request->user(), $patient->id, 'sharing.granted', 'clinical-share', $share->id);
        });

        return back()->with('status', 'Partage en lecture seule accordé pour vos éléments de ce patient.');
    }

    public function revoke(Request $request, Patient $patient, ClinicalShare $share, AppointmentScheduler $scheduler)
    {
        abort_unless($share->patient_id === $patient->id && $share->owner_id === $request->user()->id, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $scheduler->transaction(function () use ($request, $patient, $share, $scheduler, $data) {
            $scheduler->lockDoctors([$request->user()->id, $share->viewer_id]);
            abort_unless(User::findOrFail($request->user()->id)->hasPermission('medical-record.write'), 403);
            $share = ClinicalShare::whereKey($share->id)->lockForUpdate()->firstOrFail();
            $share->update(['revoked_at' => now()]);
            PatientEvent::create(['patient_id' => $patient->id, 'actor_id' => $request->user()->id, 'action' => 'sharing.revoked', 'reason' => trim($data['reason']), 'metadata' => ['share_id' => $share->id, 'viewer_id' => $share->viewer_id], 'created_at' => now()]);
            MedicalAudit::record($request->user(), $patient->id, 'sharing.revoked', 'clinical-share', $share->id);
        });

        return back()->with('status', 'Partage révoqué pour les prochains accès.');
    }
}
