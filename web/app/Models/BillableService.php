<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillableService extends Model
{
    protected $guarded = ['id'];

    /** Définit les conversions de stockage et les dates utilisées par le modèle. */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'price_minor' => 'integer', 'version' => 'integer'];
    }
}
