<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** Définit les conversions de stockage et les dates utilisées par le modèle. */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
