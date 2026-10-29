<?php

namespace App\Services;

use App\Models\ClinicalShare;
use App\Models\User;

class ClinicalAccess
{
    public static function authors(User $viewer, int $patientId): array
    {
        if (! $viewer->hasPermission('medical-record.view')) {
            return [];
        }

        return array_unique([$viewer->id, ...ClinicalShare::where('patient_id', $patientId)->where('viewer_id', $viewer->id)->whereNull('revoked_at')->pluck('owner_id')->all()]);
    }

    public static function canRead(User $viewer, int $patientId, int $authorId): bool
    {
        return in_array($authorId, self::authors($viewer, $patientId), true);
    }
}
