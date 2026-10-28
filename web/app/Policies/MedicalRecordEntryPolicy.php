<?php

namespace App\Policies;

use App\Models\MedicalRecordEntry;
use App\Models\User;

class MedicalRecordEntryPolicy
{
    public function view(User $user, MedicalRecordEntry $record): bool
    {
        return $user->hasPermission('medical-record.view') && $record->doctor_id === $user->id;
    }

    public function update(User $user, MedicalRecordEntry $record): bool
    {
        return $user->hasPermission('medical-record.write') && $record->doctor_id === $user->id;
    }
}
