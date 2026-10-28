<?php

namespace Tests\Feature;

use App\Models\BillableService;
use App\Models\BillingEvent;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Patient;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cashier;

    private Patient $patient;

    private BillableService $act;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clinic.currency' => 'XOF']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        $this->act = BillableService::create(['code' => 'ACT-01', 'name' => 'Acte administratif fictif', 'currency' => 'XOF', 'price_minor' => 5000, 'active' => true, 'version' => 1]);
        $this->actingAs($this->admin);
    }

    private function data(array $extra = []): array
    {
        return array_replace(['patient_id' => $this->patient->id, 'token' => (string) Str::uuid(), 'lines' => [['service_id' => $this->act->id, 'quantity' => 2]]], $extra);
    }

    private function invoice(bool $issue = false): Invoice
    {
        $this->post('/billing/invoices', $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $invoice = Invoice::latest('id')->firstOrFail();
        if ($issue) {
            $this->post('/billing/invoices/'.$invoice->id.'/issue', ['version' => $invoice->version])->assertSessionHasNoErrors();
        }

        return $invoice->fresh();
    }

    private function payment(Invoice $invoice, string $amount = '4000', ?string $token = null)
    {
        return $this->post('/billing/invoices/'.$invoice->id.'/payments', ['amount' => $amount, 'method' => 'cash', 'token' => $token ?? (string) Str::uuid()]);
    }

    public function test_catalog_is_admin_only_and_price_is_parsed_exactly(): void
    {
        $this->post('/billing/catalog', ['code' => 'ACT-02', 'name' => 'Acte fictif', 'price' => '1234', 'active' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('billable_services', ['code' => 'ACT-02', 'currency' => 'XOF', 'price_minor' => 1234]);
        $this->actingAs($this->cashier)->post('/billing/catalog', ['code' => 'BAD', 'name' => 'Non autorisé', 'price' => '1', 'active' => true])->assertForbidden();
        $this->get('/billing/catalog')->assertForbidden();
    }

    public function test_exact_minor_unit_parser_never_uses_floating_point_arithmetic(): void
    {
        $this->assertSame(101, Money::minor('1.01', 'EUR'));
        $this->assertSame(110, Money::minor('1,1', 'EUR'));
        $this->assertSame(1, Money::minor('0.01', 'USD'));
        $this->assertSame(123, Money::minor('123', 'XOF'));
        $this->assertSame('1,01 EUR', Money::format(101, 'EUR'));
        foreach (['1.01', '1,5', '-1', '0', '1e3', '1 000'] as $value) {
            $this->post('/billing/catalog', ['code' => 'BAD', 'name' => 'Test', 'price' => $value, 'active' => true])->assertSessionHasErrors('price');
        }
    }

    public function test_draft_total_uses_catalog_prices_and_patient_snapshot(): void
    {
        $data = $this->data(['total_minor' => 1, 'paid_minor' => 999, 'status' => 'paid']);
        $this->post('/billing/invoices', $data)->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->assertSame(10000, $invoice->total_minor);
        $this->assertSame(0, $invoice->paid_minor);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('Fictif Alice', $invoice->patient_name);
        $this->assertNull($invoice->number);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'unit_price_minor' => 5000, 'total_minor' => 10000]);
        $this->get('/billing/invoices/'.$invoice->id)->assertInertia(fn (Assert $page) => $page->component('billing/show', false)->missing('invoice.create_token')->has('invoice.lines', 1));
    }

    public function test_creation_key_prevents_duplicates_and_rejects_changed_payload(): void
    {
        $data = $this->data();
        $this->post('/billing/invoices', $data)->assertSessionHasNoErrors();
        $this->post('/billing/invoices', $data)->assertSessionHasNoErrors();
        $data['lines'][0]['quantity'] = 3;
        $this->post('/billing/invoices', $data)->assertSessionHasErrors('token');
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('billing_events', 1);
    }

    public function test_issue_is_numbered_once_and_preserves_prices_and_patient_identity(): void
    {
        $invoice = $this->invoice();
        $this->post('/billing/invoices/'.$invoice->id.'/issue', ['version' => 1])->assertSessionHasNoErrors();
        $this->post('/billing/invoices/'.$invoice->id.'/issue', ['version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('MC-F-00000001', $invoice->fresh()->number);
        $this->act->update(['name' => 'Nouveau libellé', 'price_minor' => 9000]);
        $this->patient->update(['first_name' => 'Autre prénom']);
        $this->assertSame('Acte administratif fictif', $invoice->lines()->first()->label);
        $this->assertSame(10000, $invoice->fresh()->total_minor);
        $this->assertSame('Fictif Alice', $invoice->fresh()->patient_name);
        $this->assertSame(1, BillingEvent::where('action', 'invoice.issued')->count());
    }

    public function test_partial_and_full_payments_update_balance_and_status(): void
    {
        $invoice = $this->invoice(true);
        $this->actingAs($this->cashier);
        $this->payment($invoice)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame(4000, $invoice->fresh()->paid_minor);
        $this->payment($invoice, '6000')->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(10000, $invoice->fresh()->paid_minor);
        $this->assertDatabaseHas('invoice_payments', ['receipt_number' => 'MC-R-00000002', 'balance_after_minor' => 0]);
    }

    public function test_duplicate_payment_returns_same_receipt_even_after_full_payment(): void
    {
        $invoice = $this->invoice(true);
        $token = (string) Str::uuid();
        $this->payment($invoice, '10000', $token)->assertSessionHasNoErrors();
        $this->payment($invoice, '10000', $token)->assertSessionHasNoErrors();
        $this->payment($invoice, '9000', $token)->assertSessionHasErrors('token');
        $this->assertDatabaseCount('invoice_payments', 1);
        $this->assertSame(10000, $invoice->fresh()->paid_minor);
    }

    public function test_overspending_draft_cancelled_paid_and_invalid_precision_are_rejected(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice)->assertSessionHasErrors('amount');
        $this->post('/billing/invoices/'.$invoice->id.'/issue', ['version' => 1])->assertSessionHasNoErrors();
        $this->payment($invoice, '10001')->assertSessionHasErrors('amount');
        $this->payment($invoice, '1.50')->assertSessionHasErrors('amount');
        $this->payment($invoice, '-5')->assertSessionHasErrors('amount');
        $this->payment($invoice, '10000')->assertSessionHasNoErrors();
        $this->payment($invoice, '1')->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('invoice_payments', 1);
    }

    public function test_draft_correction_is_versioned_and_issued_lines_are_immutable(): void
    {
        $invoice = $this->invoice();
        $payload = ['version' => 1, 'reason' => 'Quantité corrigée', 'lines' => [['service_id' => $this->act->id, 'quantity' => 3]]];
        $this->patch('/billing/invoices/'.$invoice->id, $payload)->assertSessionHasNoErrors();
        $this->assertSame(15000, $invoice->fresh()->total_minor);
        $this->assertSame(2, $invoice->fresh()->version);
        $this->patch('/billing/invoices/'.$invoice->id, $payload)->assertSessionHasErrors('version');
        $this->assertSame(2, BillingEvent::where('invoice_id', $invoice->id)->count());
        $this->post('/billing/invoices/'.$invoice->id.'/issue', ['version' => 2])->assertSessionHasNoErrors();
        $payload['version'] = 3;
        $this->patch('/billing/invoices/'.$invoice->id, $payload)->assertSessionHasErrors('lines');
        $this->assertSame(10000, BillingEvent::where('action', 'invoice.created')->first()->snapshot['invoice']['total_minor']);
    }

    public function test_admin_void_retains_receipt_and_recomputes_balance_then_can_cancel(): void
    {
        $invoice = $this->invoice(true);
        $this->payment($invoice)->assertSessionHasNoErrors();
        $payment = InvoicePayment::firstOrFail();
        $this->post('/billing/invoices/'.$invoice->id.'/cancel', ['version' => $invoice->fresh()->version, 'reason' => 'Correction'])->assertSessionHasErrors('reason');
        $this->post('/billing/payments/'.$payment->id.'/void', ['reason' => 'Saisie erronée'])->assertSessionHasNoErrors();
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->paid_minor);
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->post('/billing/payments/'.$payment->id.'/void', ['reason' => 'Nouvelle soumission'])->assertSessionHasNoErrors();
        $this->assertSame(1, BillingEvent::where('action', 'payment.voided')->count());
        $this->post('/billing/invoices/'.$invoice->id.'/cancel', ['version' => $invoice->fresh()->version, 'reason' => 'Facture à remplacer'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->payment($invoice)->assertSessionHasErrors('amount');
        $this->get('/billing/receipts/'.$payment->id)->assertOk()->assertSee('ÉCRITURE DE PAIEMENT ANNULÉE');
    }

    public function test_cashier_cannot_cancel_or_void_and_can_access_only_minimal_patient_picker(): void
    {
        $invoice = $this->invoice(true);
        $this->payment($invoice)->assertSessionHasNoErrors();
        $this->actingAs($this->cashier);
        $this->post('/billing/invoices/'.$invoice->id.'/cancel', ['reason' => 'Non autorisé', 'version' => 3])->assertForbidden();
        $this->post('/billing/payments/'.InvoicePayment::firstOrFail()->id.'/void', ['reason' => 'Non autorisé'])->assertForbidden();
        $this->get('/patients/'.$this->patient->id)->assertForbidden();
        $this->getJson('/billing/patients?search=Alice')->assertOk()->assertJsonPath('0.id', $this->patient->id)->assertJsonMissingPath('0.email')->assertJsonMissingPath('0.address');
    }

    public function test_non_financial_roles_are_denied_print_receipt_and_every_write(): void
    {
        $invoice = $this->invoice(true);
        $this->payment($invoice)->assertSessionHasNoErrors();
        $payment = InvoicePayment::firstOrFail();
        foreach (['doctor', 'secretary', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach (['/billing', '/billing/create', '/billing/catalog', '/billing/invoices/'.$invoice->id, '/billing/invoices/'.$invoice->id.'/print', '/billing/receipts/'.$payment->id, '/billing/patients?search=Alice'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/billing/invoices', $this->data())->assertForbidden();
            $this->patch('/billing/invoices/'.$invoice->id, ['version' => 1])->assertForbidden();
            $this->post('/billing/invoices/'.$invoice->id.'/issue', ['version' => 1])->assertForbidden();
            $this->payment($invoice)->assertForbidden();
        }
    }

    public function test_printable_invoice_and_receipt_escape_content_and_keep_cache_private(): void
    {
        $this->patient->update(['last_name' => '<script>unsafe</script>']);
        $invoice = $this->invoice(true);
        $this->payment($invoice)->assertSessionHasNoErrors();
        $print = $this->get('/billing/invoices/'.$invoice->id.'/print')->assertOk()->assertSee('MC-F-00000001')->assertDontSee('<script>unsafe</script>', false);
        $this->assertStringContainsString('no-store', $print->headers->get('Cache-Control'));
        $this->get('/billing/receipts/'.InvoicePayment::firstOrFail()->id)->assertOk()->assertSee('MC-R-00000001')->assertSee('4000 XOF')->assertSee('6000 XOF');
    }

    public function test_euro_decimal_lines_and_payment_are_exact(): void
    {
        config(['clinic.currency' => 'EUR']);
        $this->act->update(['currency' => 'EUR', 'price_minor' => 101]);
        $invoice = $this->invoice(true);
        $this->assertSame(202, $invoice->total_minor);
        $this->payment($invoice, '1,01')->assertSessionHasNoErrors();
        $this->assertSame(101, $invoice->fresh()->paid_minor);
        $this->payment($invoice, '1.01')->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->payment($invoice, '0.001')->assertSessionHasErrors('amount');
    }

    public function test_currency_mismatch_inactive_service_quantity_and_client_prices_rejected(): void
    {
        $this->act->update(['active' => false]);
        $this->post('/billing/invoices', $this->data())->assertSessionHasErrors('lines');
        $this->act->update(['active' => true, 'currency' => 'EUR']);
        $this->post('/billing/invoices', $this->data())->assertSessionHasErrors('lines');
        $this->post('/billing/invoices', $this->data(['lines' => [['service_id' => $this->act->id, 'quantity' => 0]]]))->assertSessionHasErrors('lines.0.quantity');
        $this->post('/billing/invoices', $this->data(['lines' => [['service_id' => $this->act->id, 'quantity' => 1, 'unit_price_minor' => 1]]]))->assertSessionHasErrors('lines.0');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_catalog_price_changes_are_versioned_and_audited(): void
    {
        $this->patch('/billing/catalog/'.$this->act->id, ['code' => $this->act->code, 'name' => $this->act->name, 'price' => '6500', 'active' => true, 'version' => 1, 'reason' => 'Tarif mis à jour'])->assertSessionHasNoErrors();
        $this->assertSame(6500, $this->act->fresh()->price_minor);
        $this->assertSame(2, $this->act->fresh()->version);
        $this->assertDatabaseHas('billing_events', ['action' => 'catalog.updated', 'reason' => 'Tarif mis à jour']);
        $this->patch('/billing/catalog/'.$this->act->id, ['code' => $this->act->code, 'name' => $this->act->name, 'price' => '5000', 'active' => true, 'version' => 1, 'reason' => 'Ancien onglet'])->assertSessionHasErrors('version');
    }

    public function test_cancelled_draft_cannot_be_issued_or_modified_and_cannot_be_deleted(): void
    {
        $invoice = $this->invoice();
        $this->post('/billing/invoices/'.$invoice->id.'/cancel', ['version' => 1, 'reason' => 'Erreur de patient'])->assertSessionHasNoErrors();
        $this->post('/billing/invoices/'.$invoice->id.'/issue', ['version' => 2])->assertSessionHasErrors('version');
        $this->delete('/billing/invoices/'.$invoice->id)->assertStatus(405);
        $this->assertNull($invoice->fresh()->number);
    }

    public function test_cashier_with_financial_entries_cannot_delete_account(): void
    {
        $this->actingAs($this->cashier);
        $this->invoice();
        $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
    }

    public function test_catalog_and_creation_pages_provide_required_data(): void
    {
        $this->get('/billing/catalog')->assertInertia(fn (Assert $page) => $page->component('billing/catalog', false)->has('events.data')->where('currency', 'XOF')->has('timezone'));
        $this->actingAs($this->cashier)->get('/billing/create')->assertInertia(fn (Assert $page) => $page->component('billing/create', false)->has('services', 1)->where('currency', 'XOF'));
    }

    public function test_failed_audit_rolls_back_payment_balance_and_receipt_number(): void
    {
        $invoice = $this->invoice(true);
        config(['app.debug' => false]);
        BillingEvent::creating(function () {
            throw new \RuntimeException('Simulated audit failure');
        });
        try {
            $this->payment($invoice)->assertStatus(500);
            $this->assertDatabaseCount('invoice_payments', 0);
            $this->assertSame(0, $invoice->fresh()->paid_minor);
            $this->assertDatabaseHas('billing_sequences', ['name' => 'receipt', 'value' => 0]);
        } finally {
            BillingEvent::flushEventListeners();
        }
        $this->payment($invoice)->assertSessionHasNoErrors();
        $this->assertSame('MC-R-00000001', InvoicePayment::firstOrFail()->receipt_number);
    }

    public function test_filtered_list_and_patient_page_only_expose_financial_summary_to_authorized_roles(): void
    {
        $invoice = $this->invoice();
        $this->get('/billing?status=draft&patient_id='.$this->patient->id)->assertInertia(fn (Assert $page) => $page->component('billing/index', false)->has('invoices.data', 1));
        $this->get('/patients/'.$this->patient->id)->assertInertia(fn (Assert $page) => $page->has('invoices', 1));
        $this->actingAs(User::factory()->create(['role' => 'doctor']))->get('/patients/'.$this->patient->id)->assertInertia(fn (Assert $page) => $page->missing('invoices'));
    }
}
