<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\StaffAbsence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentScheduler
{
    public function transaction(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            if (DB::getDriverName() === 'sqlite') {
                // Acquire the writer lock as the first statement, before a read snapshot exists.
                DB::table('users')->where('role', 'doctor')->update(['updated_at' => DB::raw('updated_at')]);
            }

            return $callback();
        }, 5);
    }

    // Every scheduling/availability mutation takes the same doctor lock before reading conflicts.
    public function lockDoctors(array $ids): void
    {
        $ids = array_unique($ids);
        sort($ids);
        foreach ($ids as $id) {
            if (DB::getDriverName() === 'sqlite') {
                // SQLite has no SELECT FOR UPDATE: take its writer lock before any reads.
                User::whereKey($id)->update(['updated_at' => DB::raw('updated_at')]);
            }
            User::whereKey($id)->lockForUpdate()->firstOrFail();
        }
    }

    public function book(array $data, CarbonImmutable $start, CarbonImmutable $end, ?int $id = null): Appointment
    {
        return $this->transaction(function () use ($data, $start, $end, $id) {
            // A move can change doctors. A global, ordered lock set avoids stale-source races.
            // Only active doctors are selectable; include historical doctors owning appointments.
            $doctorIds = User::where('role', 'doctor')->pluck('id')->all();
            if ($id) {
                $doctorIds[] = Appointment::findOrFail($id)->doctor_id;
            }
            $doctorIds[] = (int) $data['doctor_id'];
            $this->lockDoctors($doctorIds);
            $appointment = $id ? Appointment::whereKey($id)->lockForUpdate()->firstOrFail() : new Appointment;
            if ($id && ! in_array($appointment->status, ['scheduled', 'confirmed'], true)) {
                throw ValidationException::withMessages(['starts_at' => 'Seuls les rendez-vous prévus ou confirmés peuvent être déplacés.']);
            }
            $doctor = User::findOrFail($data['doctor_id']);
            if ($doctor->role !== 'doctor' || $doctor->status !== 'active') {
                throw ValidationException::withMessages(['doctor_id' => 'Choisissez un médecin actif.']);
            }
            if ($start < CarbonImmutable::now('UTC')) {
                throw ValidationException::withMessages(['starts_at' => 'Le rendez-vous doit commencer dans le futur.']);
            }
            if (StaffAbsence::where('user_id', $doctor->id)->where('status', 'active')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Le médecin est absent sur ce créneau.']);
            }
            if (! DoctorAvailability::where('doctor_id', $doctor->id)->where('starts_at', '<=', $start)->where('ends_at', '>=', $end)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Le créneau doit être entièrement compris dans une disponibilité du médecin.']);
            }
            if (Appointment::where('doctor_id', $doctor->id)->whereIn('status', Appointment::OPEN)->when($id, fn ($query) => $query->whereKeyNot($id))->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Ce médecin a déjà un rendez-vous sur ce créneau.']);
            }
            if (Appointment::where('patient_id', $data['patient_id'])->whereIn('status', Appointment::OPEN)->when($id, fn ($query) => $query->whereKeyNot($id))->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['patient_id' => 'Ce patient a déjà un rendez-vous sur ce créneau.']);
            }
            $appointment->fill($data);
            $appointment->starts_at = $start;
            $appointment->ends_at = $end;
            // Moving a confirmed booking requires confirmation again.
            $appointment->status = 'scheduled';
            $appointment->save();

            return $appointment;
        });
    }
}
