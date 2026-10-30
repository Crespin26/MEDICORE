<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    /** Définit les conversions de stockage et les dates utilisées par le modèle. */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'balance_after_minor' => 'integer', 'paid_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime'];
    }

    /** Associe cet enregistrement à sa facture. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
