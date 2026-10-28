<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Patient extends Model
{
    protected $fillable = ['first_name', 'last_name', 'birth_date', 'phone', 'email', 'address'];

    protected $hidden = ['identity_key', 'search_text'];

    public static function identityKey(string $firstName, string $lastName, string $birthDate): string
    {
        return hash('sha256', json_encode([Str::lower(Str::squish($firstName)), Str::lower(Str::squish($lastName)), substr($birthDate, 0, 10)], JSON_UNESCAPED_UNICODE));
    }

    public static function searchText(string ...$parts): string
    {
        return Str::lower(Str::ascii(Str::squish(implode(' ', $parts))));
    }

    protected static function booted(): void
    {
        static::saving(function (Patient $patient) {
            $patient->first_name = Str::squish($patient->first_name);
            $patient->last_name = Str::squish($patient->last_name);
            $patient->search_text = self::searchText($patient->first_name, $patient->last_name, $patient->phone ?? '', $patient->email ?? '');
            $patient->identity_key = self::identityKey($patient->first_name, $patient->last_name, $patient->birth_date->format('Y-m-d'));
        });
    }

    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d'];
    }
}
