<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Money
{
    public const SCALES = ['XOF' => 0, 'EUR' => 2, 'USD' => 2];

    public const MAX = 1000000000000;

    public static function minor(string $value, string $currency, string $field = 'amount'): int
    {
        $scale = self::SCALES[$currency] ?? null;
        abort_unless($scale !== null, 422, 'Devise non prise en charge.');
        $pattern = $scale === 0 ? '/^[0-9]{1,12}$/' : '/^([0-9]{1,12})(?:[.,]([0-9]{1,2}))?$/';
        if (! preg_match($pattern, $value, $match)) {
            throw ValidationException::withMessages([$field => 'Saisissez un montant positif sans séparateur de milliers, avec au maximum '.$scale.' décimale(s).']);
        }
        $minor = $scale === 0 ? (int) $value : (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
        if ($minor < 1 || $minor > self::MAX) {
            throw ValidationException::withMessages([$field => 'Le montant doit être supérieur à zéro et rester dans la limite autorisée.']);
        }

        return $minor;
    }

    public static function format(int $minor, string $currency): string
    {
        $scale = self::SCALES[$currency];
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $scale === 0 ? $sign.$minor.' '.$currency : $sign.intdiv($minor, 100).','.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT).' '.$currency;
    }
}
