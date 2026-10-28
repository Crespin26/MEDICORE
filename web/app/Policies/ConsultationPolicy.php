<?php

namespace App\Policies;

use App\Models\Consultation;
use App\Models\User;

class ConsultationPolicy
{
    public function view(User $user, Consultation $consultation): bool
    {
        return $user->hasPermission('consultations.view') && $consultation->doctor_id === $user->id;
    }

    public function update(User $user, Consultation $consultation): bool
    {
        return $user->hasPermission('consultations.write') && $consultation->doctor_id === $user->id;
    }
}
