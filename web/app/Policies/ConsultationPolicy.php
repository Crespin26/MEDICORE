<?php

namespace App\Policies;

use App\Models\Consultation;
use App\Models\User;
use App\Services\ClinicalAccess;

class ConsultationPolicy
{
    /** Autorise la lecture pour l’auteur ou un bénéficiaire d’un partage direct actif. */
    public function view(User $user, Consultation $consultation): bool
    {
        return $user->hasPermission('consultations.view') && ClinicalAccess::canRead($user, $consultation->patient_id, $consultation->doctor_id);
    }

    /** Réserve les modifications à l’auteur actif disposant de la permission d’écriture. */
    public function update(User $user, Consultation $consultation): bool
    {
        return $user->hasPermission('consultations.write') && $consultation->doctor_id === $user->id;
    }
}
