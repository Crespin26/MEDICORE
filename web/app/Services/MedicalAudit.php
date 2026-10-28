<?php

namespace App\Services;

use App\Models\MedicalAccessEvent;
use App\Models\User;

class MedicalAudit
{
    public static function record(User $actor, int $patientId, string $action, string $subjectType, int $subjectId, ?int $version = null): void
    {
        MedicalAccessEvent::create([
            'patient_id' => $patientId, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
            'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'version' => $version, 'created_at' => now(),
        ]);
    }
}
