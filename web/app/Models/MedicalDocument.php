<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'original_name' => 'encrypted'];
    }
}
