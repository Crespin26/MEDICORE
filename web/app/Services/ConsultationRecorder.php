<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConsultationRecorder
{
    public function __construct(private AppointmentScheduler $scheduler) {}

    public function save(User $actor, Patient $patient, array $data, ?int $id = null): Consultation
    {
        return $this->scheduler->transaction(function () use ($actor, $patient, $data, $id) {
            $this->scheduler->lockDoctors([$actor->id]);
            $actor = User::findOrFail($actor->id);
            abort_unless($actor->hasPermission('consultations.write'), 403);
            $consultation = $id ? Consultation::whereKey($id)->lockForUpdate()->firstOrFail() : new Consultation;
            if ($id) {
                Gate::forUser($actor)->authorize('update', $consultation);
                if ((int) $data['version'] !== $consultation->version) {
                    throw ValidationException::withMessages(['version' => 'Cette consultation a été modifiée. Rechargez la page avant de poursuivre.']);
                }
            }
            $wasValidated = $consultation->status === 'validated';
            if ($wasValidated && ($data['action'] !== 'validate' || trim($data['correction_reason'] ?? '') === '')) {
                throw ValidationException::withMessages(['correction_reason' => 'Une correction après validation nécessite un motif et conserve le statut validé.']);
            }
            $time = ClinicTime::parse($data['consulted_at'], 'consulted_at');
            if ($time > CarbonImmutable::now('UTC') || $time->setTimezone(config('clinic.timezone'))->toDateString() < $patient->birth_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['consulted_at' => 'La date doit se situer entre la naissance du patient et l’heure actuelle.']);
            }
            if (! $id) {
                $consultation->patient_id = $patient->id;
                $consultation->doctor_id = $actor->id;
                $appointmentId = $data['appointment_id'] ?? null;
                if ($appointmentId) {
                    $appointment = Appointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
                    abort_unless($appointment->doctor_id === $actor->id && $appointment->patient_id === $patient->id, 403);
                    if (! in_array($appointment->status, ['arrived', 'completed'], true) || $appointment->starts_at->isFuture()) {
                        throw ValidationException::withMessages(['appointment_id' => 'Le rendez-vous doit être arrivé ou terminé et avoir commencé.']);
                    }
                    if (Consultation::where('appointment_id', $appointment->id)->exists()) {
                        throw ValidationException::withMessages(['appointment_id' => 'Ce rendez-vous possède déjà une consultation.']);
                    }
                    $consultation->appointment_id = $appointment->id;
                }
            } elseif (($data['appointment_id'] ?? null) != $consultation->appointment_id) {
                throw ValidationException::withMessages(['appointment_id' => 'Le rattachement au rendez-vous ne peut pas être modifié.']);
            }
            $consultation->fill([
                'reason' => $data['reason'], 'observations' => $data['observations'] ?? null,
                'report' => $data['report'] ?? null, 'vitals' => $data['vitals'] ?? [], 'consulted_at' => $time,
            ]);
            $consultation->status = $data['action'] === 'validate' ? 'validated' : 'draft';
            if ($consultation->status === 'validated' && ! $wasValidated) {
                $consultation->validated_at = CarbonImmutable::now('UTC');
            }
            $consultation->version = $id ? $consultation->version + 1 : 1;
            $consultation->save();
            $consultation->revisions()->create([
                'author_id' => $actor->id, 'author_name' => $actor->name, 'version' => $consultation->version,
                'action' => $wasValidated ? 'corrected' : ($consultation->status === 'validated' ? 'validated' : ($id ? 'draft_updated' : 'created')),
                'correction_reason' => $wasValidated ? $data['correction_reason'] : null,
                'snapshot' => [
                    'patient_id' => $consultation->patient_id, 'doctor_id' => $consultation->doctor_id, 'appointment_id' => $consultation->appointment_id,
                    'consulted_at' => $time->toIso8601String(), 'reason' => $consultation->reason, 'observations' => $consultation->observations,
                    'report' => $consultation->report, 'vitals' => $consultation->vitals, 'status' => $consultation->status,
                    'validated_at' => $consultation->validated_at?->toIso8601String(),
                ],
                'created_at' => CarbonImmutable::now('UTC'),
            ]);

            return $consultation;
        });
    }
}
