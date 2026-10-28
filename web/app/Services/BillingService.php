<?php

namespace App\Services;

use App\Models\BillableService;
use App\Models\BillingEvent;
use App\Models\ClinicSetting;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Patient;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function transaction(User $actor, string $permission, callable $callback): mixed
    {
        return DB::transaction(function () use ($actor, $permission, $callback) {
            // Shared mutex also reserves SQLite's writer lock before the first read.
            DB::table('billing_sequences')->where('name', 'invoice')->update(['value' => DB::raw('value')]);
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasPermission($permission), 403);

            return $callback($actor);
        }, 5);
    }

    public function number(string $kind): string
    {
        $row = DB::table('billing_sequences')->where('name', $kind)->lockForUpdate()->first();
        abort_if($row->value >= 999999999, 409, 'Limite de numérotation atteinte.');
        $number = $row->value + 1;
        DB::table('billing_sequences')->where('name', $kind)->update(['value' => $number]);

        return ($kind === 'invoice' ? 'MC-F-' : 'MC-R-').str_pad((string) $number, 8, '0', STR_PAD_LEFT);
    }

    public function event(User $actor, string $action, ?Invoice $invoice = null, ?BillableService $service = null, ?string $reason = null, array $extra = []): void
    {
        BillingEvent::create(['invoice_id' => $invoice?->id, 'billable_service_id' => $service?->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'action' => $action, 'reason' => $reason,
            'snapshot' => $invoice ? ['invoice' => $invoice->toArray(), 'lines' => $invoice->lines()->get()->toArray(), ...$extra] : ['service' => $service->toArray()], 'created_at' => now()]);
    }

    private function lines(array $items, string $currency): array
    {
        $total = 0;
        $lines = [];
        foreach ($items as $index => $item) {
            $service = BillableService::findOrFail($item['service_id']);
            if (! $service->active || $service->currency !== $currency) {
                throw ValidationException::withMessages(['lines' => 'Chaque acte doit être actif et utiliser la devise de la facture.']);
            }
            $lineTotal = $service->price_minor * (int) $item['quantity'];
            $total += $lineTotal;
            if ($total > Money::MAX) {
                throw ValidationException::withMessages(['lines' => 'Le total dépasse la limite autorisée.']);
            }
            $lines[] = ['billable_service_id' => $service->id, 'code' => $service->code, 'label' => $service->name, 'quantity' => (int) $item['quantity'], 'unit_price_minor' => $service->price_minor, 'total_minor' => $lineTotal];
        }
        if ($total < 1) {
            throw ValidationException::withMessages(['lines' => 'Le total doit être supérieur à zéro.']);
        }

        return [$lines, $total];
    }

    public function create(User $actor, array $data): Invoice
    {
        return $this->transaction($actor, 'billing.manage', function ($actor) use ($data) {
            $setting = ClinicSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $currency = $setting->currency ?? config('clinic.currency');
            abort_unless(isset(Money::SCALES[$currency]), 422, 'Devise non prise en charge.');
            $fingerprint = hash('sha256', json_encode(['patient_id' => (int) $data['patient_id'], 'currency' => $data['currency'] ?? null, 'lines' => array_map(fn ($line) => [(int) $line['service_id'], (int) $line['quantity']], $data['lines'])]));
            $existing = Invoice::where('create_token', $data['token'])->first();
            if ($existing) {
                if ($existing->created_by !== $actor->id || $existing->request_fingerprint !== $fingerprint) {
                    throw ValidationException::withMessages(['token' => 'Cette clé a déjà été utilisée pour une autre demande.']);
                }

                return $existing;
            }
            if (isset($data['currency']) && $data['currency'] !== $currency) {
                throw ValidationException::withMessages(['currency' => 'La devise de l’établissement a changé. Rechargez le formulaire.']);
            }
            [$lines, $total] = $this->lines($data['lines'], $currency);
            $patient = Patient::findOrFail($data['patient_id']);
            $invoice = Invoice::create(['patient_id' => $patient->id, 'patient_name' => $patient->last_name.' '.$patient->first_name, 'created_by' => $actor->id, 'create_token' => $data['token'], 'request_fingerprint' => $fingerprint, 'currency' => $currency, 'status' => 'draft', 'total_minor' => $total, 'paid_minor' => 0, 'version' => 1]);
            $invoice->lines()->createMany($lines);
            $this->event($actor, 'invoice.created', $invoice);

            return $invoice;
        });
    }

    public function update(User $actor, int $id, array $data): Invoice
    {
        return $this->transaction($actor, 'billing.manage', function ($actor) use ($id, $data) {
            $invoice = Invoice::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->version($invoice, $data['version']);
            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages(['lines' => 'Seuls les brouillons sont modifiables.']);
            }
            [$lines, $total] = $this->lines($data['lines'], $invoice->currency);
            $invoice->lines()->delete();
            $invoice->lines()->createMany($lines);
            $invoice->total_minor = $total;
            $invoice->version++;
            $invoice->save();
            $this->event($actor, 'invoice.updated', $invoice, reason: $data['reason']);

            return $invoice;
        });
    }

    public function version(Invoice $invoice, int $version): void
    {
        if ($invoice->version !== $version) {
            throw ValidationException::withMessages(['version' => 'Cette facture a changé. Rechargez-la avant de poursuivre.']);
        }
    }

    public function issue(User $actor, int $id, int $version): Invoice
    {
        return $this->transaction($actor, 'billing.manage', function ($actor) use ($id, $version) {
            $invoice = Invoice::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages(['version' => 'Une facture annulée ne peut pas être émise.']);
            }
            if ($invoice->status !== 'draft') {
                return $invoice;
            }
            $this->version($invoice, $version);
            $issuer = ClinicSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $invoice->issuer_details = $issuer->only('name', 'phone', 'email', 'address');
            $invoice->number = $this->number('invoice');
            $invoice->status = 'issued';
            $invoice->issued_at = now();
            $invoice->version++;
            $invoice->save();
            $this->event($actor, 'invoice.issued', $invoice);

            return $invoice;
        });
    }

    public function cancel(User $actor, int $id, int $version, string $reason): void
    {
        $this->transaction($actor, 'billing.reverse', function ($actor) use ($id, $version, $reason) {
            $invoice = Invoice::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'cancelled') {
                return;
            }
            $this->version($invoice, $version);
            if ($invoice->paid_minor !== 0) {
                throw ValidationException::withMessages(['reason' => 'Annulez d’abord les écritures de paiement actives.']);
            }
            $invoice->status = 'cancelled';
            $invoice->cancelled_at = now();
            $invoice->version++;
            $invoice->save();
            $this->event($actor, 'invoice.cancelled', $invoice, reason: $reason);
        });
    }

    public function pay(User $actor, int $id, array $data): InvoicePayment
    {
        return $this->transaction($actor, 'billing.pay', function ($actor) use ($id, $data) {
            $invoice = Invoice::whereKey($id)->lockForUpdate()->firstOrFail();
            $amount = Money::minor($data['amount'], $invoice->currency);
            $existing = InvoicePayment::where('token', $data['token'])->first();
            if ($existing) {
                if ($existing->invoice_id !== $id || $existing->amount_minor !== $amount || $existing->method !== $data['method'] || $existing->reference !== ($data['reference'] ?? null) || $existing->recorded_by !== $actor->id) {
                    throw ValidationException::withMessages(['token' => 'Cette clé correspond déjà à un autre paiement.']);
                }

                return $existing;
            }
            if (! in_array($invoice->status, ['issued', 'partially_paid'], true) || $amount > $invoice->total_minor - $invoice->paid_minor) {
                throw ValidationException::withMessages(['amount' => 'Le paiement dépasse le solde ou la facture ne peut pas recevoir de paiement.']);
            }
            $payment = InvoicePayment::create(['invoice_id' => $invoice->id, 'recorded_by' => $actor->id, 'recorder_name' => $actor->name, 'token' => $data['token'], 'receipt_number' => $this->number('receipt'), 'amount_minor' => $amount, 'balance_after_minor' => $invoice->total_minor - $invoice->paid_minor - $amount, 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'paid_at' => now()]);
            $this->refreshBalance($invoice);
            $this->event($actor, 'payment.recorded', $invoice, extra: ['payment' => $payment->toArray()]);

            return $payment;
        });
    }

    private function refreshBalance(Invoice $invoice): void
    {
        $invoice->paid_minor = (int) $invoice->payments()->whereNull('voided_at')->sum('amount_minor');
        $invoice->status = $invoice->paid_minor === $invoice->total_minor ? 'paid' : ($invoice->paid_minor > 0 ? 'partially_paid' : 'issued');
        $invoice->version++;
        $invoice->save();
    }

    public function void(User $actor, int $paymentId, string $reason): void
    {
        $this->transaction($actor, 'billing.reverse', function ($actor) use ($paymentId, $reason) {
            $payment = InvoicePayment::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if ($payment->voided_at) {
                return;
            }
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $payment->voided_at = now();
            $payment->voided_by = $actor->id;
            $payment->voider_name = $actor->name;
            $payment->void_reason = $reason;
            $payment->save();
            $this->refreshBalance($invoice);
            $this->event($actor, 'payment.voided', $invoice, reason: $reason, extra: ['payment' => $payment->toArray()]);
        });
    }
}
