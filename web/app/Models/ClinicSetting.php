<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicSetting extends Model
{
    protected $table = 'clinic_settings';

    protected $guarded = ['id'];

    /** Définit les conversions de stockage et les dates utilisées par le modèle. */
    protected function casts(): array
    {
        return ['opening_hours' => 'array', 'version' => 'integer'];
    }
}
