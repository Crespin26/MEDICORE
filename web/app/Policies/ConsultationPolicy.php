<?php

namespace App\Policies;

use App\Models\Consultation;
use App\Models\User;
use App\Services\ClinicalAccess;

class ConsultationPolicy
{
    public function view(User $user, Consultation $consultation): bool
    {
        return $user->hasPermission('consultations.view') && ClinicalAccess::canRead($user, $consultation->patient_id, $consultation->doctor_id);
    }

    public function update(User $user, Consultation $consultation): bool
    {
        return $user->hasPermission('consultations.write') && $consultation->doctor_id === $user->id;
    }
}
