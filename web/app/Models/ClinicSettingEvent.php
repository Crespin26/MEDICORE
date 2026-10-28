<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicSettingEvent extends Model
{
    protected $table = 'clinic_setting_events';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
