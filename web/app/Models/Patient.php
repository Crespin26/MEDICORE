<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Patient extends Model
{
    protected $fillable = ['first_name', 'last_name', 'birth_date', 'phone', 'email', 'address'];

    protected $hidden = ['identity_key', 'search_text'];

    /** Interdit une nouvelle opération métier sur une fiche archivée. */
    public function assertActive(): void
    {
        if ($this->archived_at !== null) {
            throw ValidationException::withMessages(['patient_id' => 'Ce patient est archivé. Un administrateur doit le réactiver avant une nouvelle opération.']);
        }
    }

    /** Calcule la clé normalisée prénom, nom et naissance servant à détecter les doublons. */
    public static function identityKey(string $firstName, string $lastName, string $birthDate): string
    {
        return hash('sha256', json_encode([Str::lower(Str::squish($firstName)), Str::lower(Str::squish($lastName)), substr($birthDate, 0, 10)], JSON_UNESCAPED_UNICODE));
    }

    /** Normalise les termes sans accents pour la recherche administrative. */
    public static function searchText(string ...$parts): string
    {
        return Str::lower(Str::ascii(Str::squish(implode(' ', $parts))));
    }

    /** Synchronise les index dérivés lors de chaque écriture sans les exposer dans les réponses. */
    protected static function booted(): void
    {
        static::saving(function (Patient $patient) {
            $patient->first_name = Str::squish($patient->first_name);
            $patient->last_name = Str::squish($patient->last_name);
            $patient->search_text = self::searchText($patient->first_name, $patient->last_name, $patient->phone ?? '', $patient->email ?? '');
            $patient->identity_key = $patient->distinct_identity ? null : self::identityKey($patient->first_name, $patient->last_name, $patient->birth_date->format('Y-m-d'));
        });
    }

    /** Définit les conversions de stockage et les dates utilisées par le modèle. */
    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d', 'archived_at' => 'immutable_datetime', 'distinct_identity' => 'boolean'];
    }
}
