<?php

namespace Tests\Feature;

use App\Models\AdministrationExport;
use App\Models\Appointment;
use App\Models\BillableService;
use App\Models\ClinicSetting;
use App\Models\ClinicSettingEvent;
use App\Models\Consultation;
use App\Models\DoctorAvailability;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Services\BillingService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clinic.timezone' => 'Africa/Porto-Novo', 'clinic.currency' => 'XOF']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        $this->actingAs($this->admin);
    }

    private function settings(array $extra = []): array
    {
        $days = [];
        for ($day = 1; $day <= 7; $day++) {
            $days[$day] = ['enabled' => $day === 1, 'opens' => '08:00', 'closes' => '17:00'];
        }

        return array_replace(['name' => 'Clinique fictive', 'phone' => '00000000', 'email' => 'clinic@example.test', 'address' => 'Adresse fictive', 'currency' => 'XOF', 'enforce_hours' => false, 'opening_hours' => $days, 'version' => 0, 'reason' => 'Configuration fictive'], $extra);
    }

    private function bookingData(): array
    {
        DoctorAvailability::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-02 07:00:00', 'ends_at' => '2026-11-02 16:00:00']);

        return ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-02T14:00', 'ends_at' => '2026-11-02T14:30'];
    }

    private function issued(string $currency = 'XOF', int $minor = 10000): Invoice
    {
        $act = BillableService::create(['code' => 'ACT-'.Str::upper(Str::random(8)), 'name' => 'Acte fictif', 'currency' => $currency, 'price_minor' => $minor, 'active' => true, 'version' => 1]);
        config(['clinic.currency' => $currency]);
        $billing = app(BillingService::class);
        $invoice = $billing->create($this->admin, ['patient_id' => $this->patient->id, 'token' => (string) Str::uuid(), 'lines' => [['service_id' => $act->id, 'quantity' => 1]]]);

        return $billing->issue($this->admin, $invoice->id, 1);
    }

    public function test_dashboard_scopes_data_by_role_and_excludes_clinical_text(): void
    {
        $other = User::factory()->create(['role' => 'doctor']);
        foreach ([$this->doctor, $other] as $doctor) {
            Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $doctor->id, 'starts_at' => '2026-11-02 08:00:00', 'ends_at' => '2026-11-02 09:00:00', 'status' => 'scheduled']);
            Consultation::forceCreate(['patient_id' => $this->patient->id, 'doctor_id' => $doctor->id, 'consulted_at' => '2026-11-02 08:00:00', 'reason' => 'Secret clinique fictif', 'status' => 'draft']);
        }
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('appointments.todayCount', 2)->missing('consultations')->has('finance'));
        $this->actingAs($this->doctor)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('appointments.todayCount', 1)->where('consultations.draft', 1)->missing('finance'))->assertDontSee('Secret clinique fictif');
        $this->actingAs(User::factory()->create(['role' => 'secretary']))->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('appointments.todayCount', 2)->missing('consultations')->missing('finance'));
        $this->actingAs(User::factory()->create(['role' => 'cashier']))->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('patientCount', null)->missing('newPatients')->missing('appointments')->missing('consultations')->has('finance'));
    }

    public function test_period_uses_clinic_midnight_and_rejects_invalid_ranges(): void
    {
        Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-01 23:30:00', 'ends_at' => '2026-11-01 23:50:00', 'status' => 'completed']);
        $this->get('/dashboard?from=2026-11-02&to=2026-11-02')->assertInertia(fn (Assert $p) => $p->where('appointments.statuses.completed', 1)->where('appointments.todayCount', 1));
        $this->get('/dashboard?from=2026-11-01&to=2026-11-01')->assertInertia(fn (Assert $p) => $p->missing('appointments.statuses.completed')->where('appointments.todayCount', 1));
        $this->get('/dashboard?from=2026-11-02&to=2026-11-01')->assertSessionHasErrors('to');
        $this->get('/dashboard?from=2026-01-01&to=2026-11-02')->assertSessionHasErrors('to');
        $this->get('/dashboard?from=2026-11-02&to=2026-11-03')->assertSessionHasErrors('to');
    }

    public function test_financial_totals_are_separated_by_currency_and_void_date(): void
    {
        $billing = app(BillingService::class);
        $xof = $this->issued();
        $eur = $this->issued('EUR', 101);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'));
        $payment = $billing->pay($this->admin, $xof->id, ['amount' => '4000', 'token' => (string) Str::uuid(), 'method' => 'cash']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));
        $billing->void($this->admin, $payment->id, 'Correction');
        $billing->pay($this->admin, $eur->id, ['amount' => '0.01', 'token' => (string) Str::uuid(), 'method' => 'cash']);
        $this->get('/dashboard?from=2026-11-02&to=2026-11-02')->assertInertia(fn (Assert $p) => $p->where('finance.0.currency', 'EUR')->where('finance.0.gross_minor', '1')->where('finance.0.outstanding_minor', '100')->where('finance.1.currency', 'XOF')->where('finance.1.net_minor', '-4000')->where('finance.1.net', '-4000 XOF')->where('finance.1.outstanding_minor', '10000'));
        $this->assertSame('-1,01 EUR', Money::format(-101, 'EUR'));
    }

    public function test_settings_are_versioned_and_visible_only_to_admin(): void
    {
        $this->get('/administration/settings')->assertInertia(fn (Assert $p) => $p->component('administration/settings', false));
        $this->patch('/administration/settings', $this->settings())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Clinique fictive', ClinicSetting::find(1)->name);
        $this->assertDatabaseHas('clinic_setting_events', ['version' => 1, 'actor_id' => $this->admin->id]);
        $this->patch('/administration/settings', $this->settings())->assertSessionHasErrors('version');
        $this->assertDatabaseCount('clinic_setting_events', 1);
        foreach (['doctor', 'secretary', 'cashier', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/administration/settings')->assertForbidden();
            $this->patch('/administration/settings', $this->settings())->assertForbidden();
        }
    }

    public function test_hours_limit_new_bookings_and_do_not_retroactively_change_appointments(): void
    {
        $data = $this->bookingData();
        $this->patch('/administration/settings', $this->settings(['enforce_hours' => true]))->assertSessionHasNoErrors();
        $this->post('/appointments', array_replace($data, ['starts_at' => '2026-11-02T17:00', 'ends_at' => '2026-11-02T17:30']))->assertSessionHasErrors('starts_at');
        $this->post('/appointments', $data)->assertSessionHasNoErrors();
        $settings = $this->settings(['enforce_hours' => true, 'version' => 1]);
        $settings['opening_hours'][1]['closes'] = '13:00';
        $this->patch('/administration/settings', $settings)->assertSessionHasErrors('opening_hours');
        $this->assertSame(1, ClinicSetting::find(1)->version);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_closed_days_and_availability_outside_hours_are_rejected(): void
    {
        $this->patch('/administration/settings', $this->settings(['enforce_hours' => true]))->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->post('/availabilities', ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-03T08:00', 'ends_at' => '2026-11-03T17:00'])->assertSessionHasErrors('starts_at');
        $this->post('/availabilities', ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-09T07:00', 'ends_at' => '2026-11-09T17:00'])->assertSessionHasErrors('starts_at');
    }

    public function test_currency_change_affects_new_operations_and_old_form_is_rejected(): void
    {
        $this->patch('/administration/settings', $this->settings(['currency' => 'EUR']))->assertSessionHasNoErrors();
        $this->post('/billing/catalog', ['code' => 'ACT-NEW', 'name' => 'Acte fictif', 'price' => '1.01', 'active' => true, 'currency' => 'EUR'])->assertSessionHasNoErrors();
        $act = BillableService::firstOrFail();
        $this->assertSame('EUR', $act->currency);
        $this->assertSame(101, $act->price_minor);
        $payload = ['patient_id' => $this->patient->id, 'token' => (string) Str::uuid(), 'currency' => 'XOF', 'lines' => [['service_id' => $act->id, 'quantity' => 1]]];
        $this->post('/billing/invoices', $payload)->assertSessionHasErrors('currency');
        $payload['currency'] = 'EUR';
        $this->post('/billing/invoices', $payload)->assertSessionHasNoErrors();
        $this->assertSame('EUR', Invoice::firstOrFail()->currency);
        $this->post('/billing/catalog', ['code' => 'BAD', 'name' => 'Ancien formulaire', 'price' => '5000', 'active' => true, 'currency' => 'XOF'])->assertSessionHasErrors('price');
    }

    public function test_issuer_contact_is_frozen_at_emission(): void
    {
        $this->patch('/administration/settings', $this->settings())->assertSessionHasNoErrors();
        $invoice = $this->issued();
        $this->patch('/administration/settings', $this->settings(['version' => 1, 'name' => 'Nouveau nom']))->assertSessionHasNoErrors();
        $this->get('/billing/invoices/'.$invoice->id.'/print')->assertOk()->assertSee('Clinique fictive')->assertDontSee('Nouveau nom');
        $this->assertSame('Clinique fictive', $invoice->fresh()->issuer_details['name']);
    }

    public function test_csv_export_is_aggregate_only_and_hash_matches_audit(): void
    {
        $this->issued();
        Consultation::forceCreate(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'consulted_at' => now(), 'reason' => 'Secret médical fictif', 'status' => 'draft']);
        $response = $this->post('/administration/exports', ['from' => '2026-11-02', 'to' => '2026-11-02', 'reason' => '=purpose-not-exported'])->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertDontSee('Alice')->assertDontSee('Secret médical fictif')->assertDontSee('=purpose-not-exported');
        $this->assertStringContainsString('patients_total_current', $response->getContent());
        $this->assertStringNotContainsString('consultations', $response->getContent());
        $export = AdministrationExport::firstOrFail();
        $this->assertSame(hash('sha256', $response->getContent()), $export->sha256);
        $this->assertSame('=purpose-not-exported', $export->reason);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/administration/exports')->assertInertia(fn (Assert $p) => $p->component('administration/exports', false)->has('exports.data', 1));
    }

    public function test_exports_require_admin_reason_and_valid_period(): void
    {
        $this->post('/administration/exports', ['reason' => ''])->assertSessionHasErrors('reason');
        foreach (['doctor', 'secretary', 'cashier', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->post('/administration/exports', ['reason' => 'Non autorisé'])->assertForbidden();
            $this->get('/administration/exports')->assertForbidden();
        }
        $this->assertDatabaseCount('administration_exports', 0);
    }

    public function test_failed_export_audit_returns_no_csv(): void
    {
        config(['app.debug' => false]);
        AdministrationExport::creating(function () {
            throw new \RuntimeException('Simulated audit failure');
        });
        try {
            $this->post('/administration/exports', ['reason' => 'Test'])->assertStatus(500)->assertDontSee('patients_total_current');
        } finally {
            AdministrationExport::flushEventListeners();
        }
    }

    public function test_closed_days_can_omit_unused_opening_times(): void
    {
        $data = $this->settings(['enforce_hours' => true]);
        for ($day = 2; $day <= 7; $day++) {
            $data['opening_hours'][$day] = ['enabled' => false];
        }
        $this->patch('/administration/settings', $data)->assertSessionHasNoErrors();
        $this->assertFalse(ClinicSetting::find(1)->opening_hours['2']['enabled']);
    }

    public function test_invalid_hours_are_rejected_and_settings_write_is_atomic(): void
    {
        $data = $this->settings(['enforce_hours' => true]);
        $data['opening_hours'][1]['closes'] = '07:00';
        $this->patch('/administration/settings', $data)->assertSessionHasErrors('opening_hours');
        config(['app.debug' => false]);
        ClinicSettingEvent::creating(function () {
            throw new \RuntimeException('Simulated audit failure');
        });
        try {
            $this->patch('/administration/settings', $this->settings())->assertStatus(500);
            $this->assertSame(0, ClinicSetting::find(1)->version);
        } finally {
            ClinicSettingEvent::flushEventListeners();
        }
    }
}
