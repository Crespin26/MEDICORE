<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    protected $fillable = ['first_name', 'last_name', 'birth_date', 'phone'];

    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d'];
    }
}
