<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportingPeriod
{
    /** Valide une période de 93 jours maximum et renvoie des bornes locales [début, fin exclusive). */
    public static function from(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $today = CarbonImmutable::now(config('clinic.timezone'))->startOfDay();
        $start = CarbonImmutable::parse($data['from'] ?? $today->subDays(29)->toDateString(), config('clinic.timezone'))->startOfDay();
        $end = CarbonImmutable::parse($data['to'] ?? $today->toDateString(), config('clinic.timezone'))->startOfDay();
        if ($end < $start || $end > $today || $start->diffInDays($end) > 92) {
            throw ValidationException::withMessages(['to' => 'Choisissez une période passée ou courante de 93 jours maximum, avec une fin après le début.']);
        }

        return [$start, $end->addDay()];
    }
}
