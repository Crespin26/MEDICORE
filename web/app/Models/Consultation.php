<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultation extends Model
{
    protected $fillable = ['reason', 'observations', 'report', 'vitals', 'consulted_at'];

    /** Chiffre les champs sensibles au repos et convertit les dates et versions du modèle. */
    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'observations' => 'encrypted', 'report' => 'encrypted', 'vitals' => 'encrypted:array', 'consulted_at' => 'immutable_datetime', 'validated_at' => 'immutable_datetime', 'version' => 'integer'];
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

    /** Expose les révisions conservées ; les écrans doivent les paginer et autoriser leur lecture. */
    public function revisions(): HasMany
    {
        return $this->hasMany(ConsultationRevision::class);
    }
}
