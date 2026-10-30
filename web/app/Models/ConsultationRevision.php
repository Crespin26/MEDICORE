<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** Chiffre les champs sensibles au repos et convertit les dates et versions du modèle. */
    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'correction_reason' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }

    /** Associe la révision au compte qui l’a enregistrée. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
