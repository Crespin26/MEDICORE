<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalShare extends Model
{
    protected $guarded = ['id'];

    /** Chiffre les champs sensibles au repos et convertit les dates et versions du modèle. */
    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'revoked_at' => 'immutable_datetime'];
    }
}
