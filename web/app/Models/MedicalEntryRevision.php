<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalEntryRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'correction_reason' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }
}
