<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillableService extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'price_minor' => 'integer', 'version' => 'integer'];
    }
}
