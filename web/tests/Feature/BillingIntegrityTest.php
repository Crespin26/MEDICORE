<?php

namespace Tests\Feature;

use App\Models\BillableService;
use App\Models\BillingEvent;
use App\Models\ClinicSetting;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Patient;
use App\Models\User;
use App\Services\BillingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Patient $patient;

    private BillableService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'clinic.currency' => 'XOF']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        $this->service = BillableService::create(['code' => 'TEST', 'name' => 'Prestation fictive', 'currency' => 'XOF', 'price_minor' => 10000, 'active' => true, 'version' => 1]);
        $this->actingAs($this->admin);
    }

    private function invoice(): Invoice
    {
        $billing = app(BillingService::class);
        $invoice = $billing->create($this->admin, ['patient_id' => $this->patient->id, 'token' => (string) Str::uuid(), 'currency' => 'XOF', 'lines' => [['service_id' => $this->service->id, 'quantity' => 1]]]);

        return $billing->issue($this->admin, $invoice->id, $invoice->version);
    }

    public function test_payment_currency_mismatch_cannot_create_or_replay_a_receipt(): void
    {
        $invoice = $this->invoice();
        $url = '/billing/invoices/'.$invoice->id.'/payments';
        $payment = ['amount' => '4000', 'method' => 'cash', 'token' => (string) Str::uuid(), 'currency' => 'EUR'];
        $this->post($url, $payment)->assertSessionHasErrors('currency');
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseHas('billing_sequences', ['name' => 'receipt', 'value' => 0]);
        $this->assertSame(0, $invoice->fresh()->paid_minor);
        $this->post($url, [...$payment, 'currency' => 'XOF'])->assertSessionHasNoErrors();
        $this->post($url, $payment)->assertSessionHasErrors('currency');
        $this->assertDatabaseCount('invoice_payments', 1);
        $this->assertSame(4000, $invoice->fresh()->paid_minor);
    }

    public function test_catalog_edit_cannot_interpret_a_price_in_another_currency(): void
    {
        $this->patch('/billing/catalog/'.$this->service->id, [
            'code' => 'TEST', 'name' => 'Prestation fictive', 'price' => '12', 'currency' => 'EUR',
            'active' => true, 'version' => 1, 'reason' => 'Correction fictive',
        ])->assertSessionHasErrors('price');
        $this->assertSame(10000, $this->service->fresh()->price_minor);
        $this->assertSame(1, $this->service->fresh()->version);
        $this->assertDatabaseCount('billing_events', 0);
    }

    public function test_existing_catalog_currency_remains_editable_after_clinic_currency_changes(): void
    {
        ClinicSetting::whereKey(1)->update(['currency' => 'EUR']);
        $this->patch('/billing/catalog/'.$this->service->id, [
            'code' => 'TEST', 'name' => 'Prestation fictive', 'price' => '12000', 'currency' => 'XOF',
            'active' => true, 'version' => 1, 'reason' => 'Correction du tarif historique',
        ])->assertSessionHasNoErrors();
        $this->assertSame('XOF', $this->service->fresh()->currency);
        $this->assertSame(12000, $this->service->fresh()->price_minor);
    }

    public function test_invoice_at_the_exact_xof_limit_can_be_paid_and_receipted(): void
    {
        $this->service->update(['price_minor' => Money::MAX]);
        $invoice = $this->invoice();
        $this->post('/billing/invoices/'.$invoice->id.'/payments', [
            'amount' => (string) Money::MAX, 'currency' => 'XOF', 'method' => 'transfer', 'token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(Money::MAX, $invoice->fresh()->paid_minor);
        $payment = InvoicePayment::firstOrFail();
        $this->assertSame(0, $payment->balance_after_minor);
        $this->get('/billing/receipts/'.$payment->id)->assertOk()->assertSee(Money::MAX.' XOF');
    }

    public function test_exact_money_limit_is_accepted_and_larger_values_are_rejected(): void
    {
        foreach (['XOF' => '1000000000000', 'EUR' => '10000000000.00', 'USD' => '10000000000,00'] as $currency => $amount) {
            $this->assertSame(Money::MAX, Money::minor($amount, $currency));
        }
        foreach (['XOF' => '1000000000001', 'EUR' => '10000000000.01', 'USD' => '10000000000,01'] as $currency => $amount) {
            try {
                Money::minor($amount, $currency);
                $this->fail('Le dépassement du plafond doit être refusé.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('amount', $error->errors());
            }
        }
    }

    public function test_money_parser_rejects_trailing_characters_even_without_http_normalization(): void
    {
        foreach (['XOF' => "1000\n", 'EUR' => "10.01\n", 'USD' => "10,01\n"] as $currency => $amount) {
            try {
                Money::minor($amount, $currency);
                $this->fail('Le parseur doit consommer toute la chaîne.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('amount', $error->errors());
            }
        }
    }

    public function test_receipt_preserves_its_original_balance_currency_and_issuer_after_later_operations(): void
    {
        ClinicSetting::whereKey(1)->update(['name' => 'Émetteur fictif initial']);
        $invoice = $this->invoice();
        $billing = app(BillingService::class);
        $first = $billing->pay($this->admin, $invoice->id, ['amount' => '4000', 'method' => 'cash', 'token' => (string) Str::uuid(), 'currency' => 'XOF']);
        $second = $billing->pay($this->admin, $invoice->id, ['amount' => '6000', 'method' => 'cash', 'token' => (string) Str::uuid()]);
        $billing->void($this->admin, $second->id, 'Annulation fictive');
        ClinicSetting::whereKey(1)->update(['name' => 'Nouvel émetteur fictif', 'currency' => 'EUR']);
        $this->patient->update(['first_name' => 'Prénom corrigé']);
        $this->get('/billing/receipts/'.$first->id)->assertOk()->assertSee('Émetteur fictif initial')
            ->assertSee('Fictif Alice')->assertSee('4000 XOF')->assertSee('6000 XOF')
            ->assertDontSee('Nouvel émetteur fictif')->assertDontSee('Prénom corrigé')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(6000, $first->fresh()->balance_after_minor);
        $this->assertSame('XOF', $invoice->fresh()->currency);
    }

    public function test_failed_void_audit_preserves_the_payment_balance_and_receipt(): void
    {
        $invoice = $this->invoice();
        $payment = app(BillingService::class)->pay($this->admin, $invoice->id, ['amount' => '10000', 'method' => 'cash', 'token' => (string) Str::uuid()]);
        BillingEvent::creating(function () {
            throw new \RuntimeException('Échec fictif de l’audit');
        });
        try {
            $this->post('/billing/payments/'.$payment->id.'/void', ['reason' => 'Annulation fictive'])->assertStatus(500);
            $this->assertNull($payment->fresh()->voided_at);
            $this->assertSame('paid', $invoice->fresh()->status);
            $this->assertSame(10000, $invoice->fresh()->paid_minor);
            $this->assertDatabaseCount('invoice_payments', 1);
            $this->assertSame($payment->receipt_number, InvoicePayment::firstOrFail()->receipt_number);
        } finally {
            BillingEvent::flushEventListeners();
        }
    }
}
