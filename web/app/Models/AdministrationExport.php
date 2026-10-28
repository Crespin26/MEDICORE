<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdministrationExport extends Model
{
    protected $table = 'administration_exports';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
