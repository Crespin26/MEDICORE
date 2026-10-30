<?php

namespace App\Support;

use App\Models\ClinicSetting;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ClinicHours
{
    /** Vérifie que tout le créneau tient dans la journée d’ouverture ; une règle vide désactive cette contrainte. */
    public static function fits(CarbonImmutable $start, CarbonImmutable $end, array $hours): bool
    {
        if (! $hours) {
            return true;
        }
        $start = $start->setTimezone(config('clinic.timezone'));
        $end = $end->setTimezone(config('clinic.timezone'));
        $day = $hours[(string) $start->isoWeekday()] ?? null;

        return $day && $day['enabled'] && $start->toDateString() === $end->toDateString() && $start->format('H:i') >= $day['opens'] && $end->format('H:i') <= $day['closes'];
    }

    /** Relit les horaires sous le verrou partagé avec les changements de paramètres avant de les appliquer. */
    public static function assertOpen(CarbonImmutable $start, CarbonImmutable $end): void
    {
        // Read the current rules under the same lock used by settings changes.
        $setting = ClinicSetting::whereKey(1)->lockForUpdate()->firstOrFail();
        if (! self::fits($start, $end, $setting->opening_hours ?? [])) {
            throw ValidationException::withMessages(['starts_at' => 'Ce créneau est en dehors des horaires d’ouverture de l’établissement.']);
        }
    }
}
