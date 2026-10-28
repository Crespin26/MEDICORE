<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClinicTime
{
    public static function interval(Request $request): array
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $start = self::parse($data['starts_at'], 'starts_at');
        $end = self::parse($data['ends_at'], 'ends_at');
        if ($end <= $start || substr($data['starts_at'], 0, 10) !== substr($data['ends_at'], 0, 10)) {
            throw ValidationException::withMessages(['ends_at' => 'La fin doit suivre le début le même jour dans le fuseau de la clinique.']);
        }

        return [$start, $end];
    }

    public static function parse(string $value, string $field): CarbonImmutable
    {
        $zone = new DateTimeZone(config('clinic.timezone'));
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        $offsets = array_unique(array_column($zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800), 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = CarbonImmutable::createFromTimestampUTC($wall->getTimestamp() - $offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d\TH:i') === $value) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages([$field => 'Cet horaire est inexistant ou ambigu dans le fuseau de la clinique. Choisissez un autre horaire.']);
        }

        return $matches[0];
    }

    public static function local(CarbonImmutable $time): string
    {
        return $time->setTimezone(config('clinic.timezone'))->format('Y-m-d\TH:i');
    }
}
