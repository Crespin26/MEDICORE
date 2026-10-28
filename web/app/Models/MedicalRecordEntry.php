<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalRecordEntry extends Model
{
    public const KINDS = ['history' => 'Antécédent', 'allergy' => 'Allergie', 'treatment' => 'Traitement déclaré'];

    protected $fillable = ['kind', 'label', 'details', 'occurred_on', 'status'];

    protected function casts(): array
    {
        return ['label' => 'encrypted', 'details' => 'encrypted', 'occurred_on' => 'date:Y-m-d', 'version' => 'integer'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MedicalEntryRevision::class);
    }
}
