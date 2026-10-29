<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalShare extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'revoked_at' => 'immutable_datetime'];
    }
}
