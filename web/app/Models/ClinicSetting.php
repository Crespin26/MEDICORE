<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicSetting extends Model
{
    protected $table = 'clinic_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opening_hours' => 'array', 'version' => 'integer'];
    }
}
