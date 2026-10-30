<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalEntryRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** Chiffre les champs sensibles au repos et convertit les dates et versions du modèle. */
    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'correction_reason' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }
}
