<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAbsence extends Model
{
    public const CATEGORIES = ['leave' => 'Congé', 'training' => 'Formation', 'unavailable' => 'Indisponibilité'];

    protected $guarded = ['id'];

    /** Définit les conversions de stockage et les dates utilisées par le modèle. */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    /** Associe l’absence au membre du personnel concerné. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
