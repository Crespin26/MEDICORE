<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentNotification extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Convertit les dates de lecture et de livraison ; aucun contenu clinique n’est stocké ici. */
    protected function casts(): array
    {
        return ['appointment_starts_at' => 'immutable_datetime', 'wants_push' => 'boolean', 'read_at' => 'immutable_datetime', 'push_dispatched_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** Revalide les droits actuels, notamment après réaffectation d’un rendez-vous. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
        if (! $user->hasPermission('appointments.view')) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereHas('appointment', fn ($q) => $q->when($user->role === 'doctor', fn ($q) => $q->where('doctor_id', $user->id)));
        }
    }

    /** Texte volontairement générique, identique pour la boîte et l’écran verrouillé. */
    public static function message(): array
    {
        return ['title' => 'MediCore', 'body' => 'Une information de rendez-vous est disponible. Ouvrez MediCore.'];
    }
}
