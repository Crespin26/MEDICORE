<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\PersonnelEvent;
use App\Models\ProfessionalProfile;
use App\Models\StaffAbsence;
use App\Models\User;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class PersonnelManager
{
    public function __construct(private AppointmentScheduler $scheduler) {}

    private function transaction(User $actor, int $userId, callable $callback): mixed
    {
        return $this->scheduler->transaction(function () use ($actor, $userId, $callback) {
            $this->scheduler->lockDoctors([$actor->id, $userId]);
            $actor = User::findOrFail($actor->id);
            abort_unless($actor->hasPermission('personnel.manage'), 403);

            return $callback($actor);
        });
    }

    private function event(User $actor, int $userId, string $action, array $snapshot, ?string $reason = null): void
    {
        PersonnelEvent::create(['user_id' => $userId, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'action' => $action, 'snapshot' => $snapshot, 'reason' => $reason, 'created_at' => now()]);
    }

    public function profile(User $actor, int $userId, array $data): void
    {
        $this->transaction($actor, $userId, function ($actor) use ($userId, $data) {
            $profile = ProfessionalProfile::where('user_id', $userId)->first();
            if (($profile?->version ?? 0) !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Cette fiche a été modifiée. Rechargez-la avant de poursuivre.']);
            }
            if (! empty($data['employee_number']) && ProfessionalProfile::where('employee_number', $data['employee_number'])->where('user_id', '!=', $userId)->exists()) {
                throw ValidationException::withMessages(['employee_number' => 'Ce matricule est déjà attribué.']);
            }
            $isNew = ! $profile;
            $profile ??= new ProfessionalProfile(['user_id' => $userId]);
            $profile->fill(array_intersect_key($data, array_flip(['employee_number', 'job_title', 'department', 'specialties', 'professional_phone', 'license_number', 'started_on'])));
            $profile->version = $isNew ? 1 : $profile->version + 1;
            $profile->save();
            $this->event($actor, $userId, $isNew ? 'profile.created' : 'profile.updated', ['profile' => $profile->toArray()], $data['reason']);
        });
    }

    public function absence(User $actor, int $userId, array $data): StaffAbsence
    {
        $start = ClinicTime::parse($data['starts_at'], 'starts_at');
        $end = ClinicTime::parse($data['ends_at'], 'ends_at');
        if ($end <= $start || $end <= CarbonImmutable::now('UTC')) {
            throw ValidationException::withMessages(['ends_at' => 'La fin doit suivre le début et se situer dans le futur.']);
        }

        return $this->transaction($actor, $userId, function ($actor) use ($userId, $data, $start, $end) {
            if (StaffAbsence::where('user_id', $userId)->where('status', 'active')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Une absence active couvre déjà tout ou partie de cette période.']);
            }
            if (Appointment::where('doctor_id', $userId)->whereIn('status', Appointment::OPEN)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Des rendez-vous actifs occupent cette période. Déplacez-les ou annulez-les avant de déclarer l’absence.']);
            }
            $absence = StaffAbsence::create(['user_id' => $userId, 'created_by' => $actor->id, 'starts_at' => $start, 'ends_at' => $end, 'category' => $data['category'], 'reason' => $data['reason'], 'status' => 'active', 'version' => 1]);
            $this->event($actor, $userId, 'absence.created', ['absence' => $absence->toArray()], $data['reason']);

            return $absence;
        });
    }

    public function cancel(User $actor, int $userId, int $absenceId, int $version, string $reason): void
    {
        $this->transaction($actor, $userId, function ($actor) use ($userId, $absenceId, $version, $reason) {
            $absence = StaffAbsence::whereKey($absenceId)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if ($absence->status === 'cancelled') {
                return;
            }
            if ($absence->version !== $version) {
                throw ValidationException::withMessages(['version' => 'Cette absence a changé. Rechargez la fiche.']);
            }
            $absence->status = 'cancelled';
            $absence->version++;
            $absence->cancelled_at = now();
            $absence->cancelled_by = $actor->id;
            $absence->cancel_reason = $reason;
            $absence->save();
            $this->event($actor, $userId, 'absence.cancelled', ['absence' => $absence->toArray()], $reason);
        });
    }
}
