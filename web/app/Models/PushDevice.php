<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['installation_id', 'installation_hash', 'personal_access_token_id'];

    /** Le identifiant Firebase reste chiffré ; seule son empreinte sert à l’unicité. */
    protected function casts(): array
    {
        return ['installation_id' => 'encrypted'];
    }
}
