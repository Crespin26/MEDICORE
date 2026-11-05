<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $guarded = ['id'];

    /** Associe les choix au personnel destinataire. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Convertit les choix explicites du personnel ; aucun canal n’est activé par défaut. */
    protected function casts(): array
    {
        return ['appointment_updates' => 'boolean', 'appointment_reminders' => 'boolean', 'push_enabled' => 'boolean', 'reminder_minutes' => 'integer'];
    }

    /** Contrat commun des valeurs initiales de l’API et des paramètres Flutter. */
    public static function defaults(): array
    {
        return ['appointment_updates' => false, 'appointment_reminders' => false, 'reminder_minutes' => 60, 'push_enabled' => false];
    }
}
