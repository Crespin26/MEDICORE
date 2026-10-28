<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfessionalProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['specialties' => 'array', 'started_on' => 'date:Y-m-d', 'version' => 'integer'];
    }
}
