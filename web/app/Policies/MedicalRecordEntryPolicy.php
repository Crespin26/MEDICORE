<?php

namespace App\Policies;

use App\Models\MedicalRecordEntry;
use App\Models\User;
use App\Services\ClinicalAccess;

class MedicalRecordEntryPolicy
{
    /** Autorise la lecture pour l’auteur ou un bénéficiaire d’un partage direct actif. */
    public function view(User $user, MedicalRecordEntry $record): bool
    {
        return $user->hasPermission('medical-record.view') && ClinicalAccess::canRead($user, $record->patient_id, $record->doctor_id);
    }

    /** Réserve les modifications à l’auteur actif disposant de la permission d’écriture. */
    public function update(User $user, MedicalRecordEntry $record): bool
    {
        return $user->hasPermission('medical-record.write') && $record->doctor_id === $user->id;
    }
}
