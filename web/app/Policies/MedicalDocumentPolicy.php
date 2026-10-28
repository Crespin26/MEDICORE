<?php

namespace App\Policies;

use App\Models\MedicalDocument;
use App\Models\User;

class MedicalDocumentPolicy
{
    public function view(User $user, MedicalDocument $record): bool
    {
        return $user->hasPermission('medical-record.view') && $record->doctor_id === $user->id;
    }

    public function update(User $user, MedicalDocument $record): bool
    {
        return $user->hasPermission('medical-record.write') && $record->doctor_id === $user->id;
    }
}
