<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public const OPEN = ['scheduled', 'confirmed', 'arrived'];

    public const STATUSES = ['scheduled' => 'Prévu', 'confirmed' => 'Confirmé', 'arrived' => 'Arrivé', 'completed' => 'Terminé', 'cancelled' => 'Annulé', 'absent' => 'Absent'];

    public const TRANSITIONS = [
        'scheduled' => ['confirmed', 'arrived', 'cancelled', 'absent'],
        'confirmed' => ['arrived', 'cancelled', 'absent'],
        'arrived' => ['completed', 'cancelled'],
        'completed' => [], 'cancelled' => [], 'absent' => [],
    ];

    protected $fillable = ['patient_id', 'doctor_id', 'starts_at', 'ends_at', 'status'];

    /** Définit les conversions de stockage et les dates utilisées par le modèle. */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /** Associe cet enregistrement à son patient. */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** Associe cet enregistrement à son médecin auteur ou responsable. */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
