<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicSettingEvent extends Model
{
    protected $table = 'clinic_setting_events';

    protected $guarded = ['id'];

    public $timestamps = false;

    /** Définit les conversions de stockage et les dates utilisées par le modèle. */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
