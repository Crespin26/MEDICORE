<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const STATUSES = ['draft' => 'Brouillon', 'issued' => 'Émise', 'partially_paid' => 'Partiellement payée', 'paid' => 'Payée', 'cancelled' => 'Annulée'];

    protected $guarded = ['id'];

    protected $hidden = ['create_token', 'request_fingerprint'];

    protected function casts(): array
    {
        return ['issuer_details' => 'array', 'total_minor' => 'integer', 'paid_minor' => 'integer', 'version' => 'integer', 'issued_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }
}
