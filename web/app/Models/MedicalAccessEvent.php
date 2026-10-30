<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalAccessEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** Définit les conversions de stockage et les dates utilisées par le modèle. */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
