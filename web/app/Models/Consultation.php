<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultation extends Model
{
    protected $fillable = ['reason', 'observations', 'report', 'vitals', 'consulted_at'];

    protected function casts(): array
    {
        return ['vitals' => 'array', 'consulted_at' => 'immutable_datetime', 'validated_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ConsultationRevision::class);
    }
}
