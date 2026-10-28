<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConsultationTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clinic.timezone' => 'Africa/Porto-Novo']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['consulted_at' => '2026-11-02T10:30', 'reason' => 'Motif fictif', 'observations' => 'Observation de démonstration', 'report' => 'Compte rendu fictif', 'vitals' => ['temperature' => '37.1', 'pulse' => '70', 'oxygen_saturation' => '98'], 'action' => 'draft'], $extra);
    }

    private function create(array $extra = []): Consultation
    {
        $this->actingAs($this->doctor)->post('/patients/'.$this->patient->id.'/consultations', $this->payload($extra))->assertRedirect()->assertSessionHasNoErrors();

        return Consultation::latest('id')->firstOrFail();
    }

    private function update(Consultation $consultation, array $extra = [])
    {
        return $this->patch('/consultations/'.$consultation->id, $this->payload(array_replace(['version' => $consultation->fresh()->version, 'appointment_id' => $consultation->appointment_id], $extra)));
    }

    public function test_create_draft_with_vitals_identity_and_initial_revision(): void
    {
        $consultation = $this->create(['doctor_id' => 999, 'patient_id' => 999, 'status' => 'validated']);
        $this->assertSame($this->doctor->id, $consultation->doctor_id);
        $this->assertSame($this->patient->id, $consultation->patient_id);
        $this->assertSame('draft', $consultation->status);
        $this->assertSame('2026-11-02 09:30', $consultation->consulted_at->format('Y-m-d H:i'));
        $this->assertNull($consultation->validated_at);
        $revision = $consultation->revisions()->firstOrFail();
        $this->assertSame($this->doctor->id, $revision->author_id);
        $this->assertSame('37.1', $revision->snapshot['vitals']['temperature']);
        $authorName = $this->doctor->name;
        $this->doctor->update(['name' => 'Nouveau nom fictif']);
        $this->assertSame($authorName, $revision->fresh()->author_name);
        $this->get('/consultations/'.$consultation->id)->assertInertia(fn (Assert $page) => $page->component('consultations/show', false)->has('revisions.data', 1)->where('consultation.consulted_at', '2026-11-02T10:30'));
    }

    public function test_draft_can_have_empty_report_but_validation_requires_one(): void
    {
        $consultation = $this->create(['report' => null, 'observations' => null, 'vitals' => []]);
        $this->update($consultation, ['action' => 'validate', 'report' => null])->assertSessionHasErrors('report');
        $this->assertSame(1, $consultation->fresh()->version);
        $this->update($consultation, ['action' => 'validate'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('validated', $consultation->fresh()->status);
        $this->assertNotNull($consultation->fresh()->validated_at);
        $this->assertSame('validated', $consultation->revisions()->latest('version')->first()->action);
    }

    public function test_corrections_preserve_previous_content_author_and_validation_time(): void
    {
        $consultation = $this->create(['action' => 'validate']);
        $validatedAt = $consultation->validated_at->toIso8601String();
        $this->travel(10)->minutes();
        $this->update($consultation, ['action' => 'validate', 'report' => 'Correction fictive', 'correction_reason' => 'Précision de saisie'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Correction fictive', $consultation->fresh()->report);
        $this->assertSame($validatedAt, $consultation->fresh()->validated_at->toIso8601String());
        $this->assertSame('Compte rendu fictif', $consultation->revisions()->where('version', 1)->first()->snapshot['report']);
        $latest = $consultation->revisions()->where('version', 2)->firstOrFail();
        $this->assertSame('corrected', $latest->action);
        $this->assertSame('Précision de saisie', $latest->correction_reason);
        $this->assertSame($this->doctor->id, $latest->author_id);
    }

    public function test_validated_record_cannot_be_reverted_or_changed_without_reason(): void
    {
        $consultation = $this->create(['action' => 'validate']);
        $this->update($consultation, ['action' => 'draft'])->assertSessionHasErrors('correction_reason');
        $this->update($consultation, ['action' => 'validate', 'correction_reason' => ' '])->assertSessionHasErrors('correction_reason');
        $this->assertSame('validated', $consultation->fresh()->status);
        $this->assertSame(1, $consultation->revisions()->count());
    }

    public function test_stale_editor_cannot_overwrite_or_append_a_revision(): void
    {
        $consultation = $this->create();
        $this->update($consultation, ['report' => 'Version la plus récente'])->assertSessionHasNoErrors();
        $this->update($consultation, ['version' => 1, 'report' => 'Ancien onglet'])->assertSessionHasErrors('version');
        $this->assertSame('Version la plus récente', $consultation->fresh()->report);
        $this->assertSame(2, $consultation->revisions()->count());
    }

    public function test_non_clinical_roles_cannot_access_any_consultation_endpoint(): void
    {
        $consultation = $this->create();
        foreach (['admin', 'secretary', 'cashier', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/consultations')->assertForbidden();
            $this->get('/consultations/'.$consultation->id)->assertForbidden();
            $this->get('/patients/'.$this->patient->id.'/consultations/create')->assertForbidden();
            $this->post('/patients/'.$this->patient->id.'/consultations', $this->payload())->assertForbidden();
            $this->patch('/consultations/'.$consultation->id, $this->payload(['version' => 1]))->assertForbidden();
        }
    }

    public function test_other_doctor_cannot_read_or_edit_and_cannot_see_record_in_list(): void
    {
        $consultation = $this->create();
        $this->actingAs(User::factory()->create(['role' => 'doctor']));
        $this->get('/consultations/'.$consultation->id)->assertForbidden();
        $this->patch('/consultations/'.$consultation->id, $this->payload(['version' => 1]))->assertForbidden();
        $this->get('/consultations?patient_id='.$this->patient->id)->assertInertia(fn (Assert $page) => $page->has('consultations.data', 0));
        $this->get('/patients/'.$this->patient->id)->assertInertia(fn (Assert $page) => $page->has('consultations', 0));
    }

    public function test_secretary_and_admin_patient_page_never_contains_clinical_summaries(): void
    {
        $this->create();
        foreach (['admin', 'secretary'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/patients/'.$this->patient->id)->assertInertia(fn (Assert $page) => $page->missing('consultations'))->assertDontSee('Motif fictif');
        }
    }

    public function test_motive_dates_vitals_and_unknown_fields_are_validated(): void
    {
        $this->actingAs($this->doctor);
        $url = '/patients/'.$this->patient->id.'/consultations';
        $this->post($url, $this->payload(['reason' => '', 'vitals' => ['oxygen_saturation' => 101]]))->assertSessionHasErrors(['reason', 'vitals.oxygen_saturation']);
        $this->post($url, $this->payload(['consulted_at' => '2026-11-03T10:30']))->assertSessionHasErrors('consulted_at');
        $this->post($url, $this->payload(['consulted_at' => '1989-01-01T10:30']))->assertSessionHasErrors('consulted_at');
        $this->post($url, $this->payload(['vitals' => ['unknown' => 123]]))->assertSessionHasErrors('vitals');
        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_clinical_fields_are_not_flashed_to_the_session_on_validation_error(): void
    {
        $this->actingAs($this->doctor)->post('/patients/'.$this->patient->id.'/consultations', $this->payload(['reason' => '', 'correction_reason' => 'Secret fictif']))->assertSessionHasErrors('reason');
        foreach (['reason', 'observations', 'report', 'vitals', 'correction_reason'] as $field) {
            $this->assertArrayNotHasKey($field, session()->getOldInput());
        }
    }

    public function test_appointment_association_is_owned_matching_and_unique(): void
    {
        $appointment = Appointment::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 09:30:00', 'status' => 'arrived']);
        $consultation = $this->create(['appointment_id' => $appointment->id]);
        $this->assertSame($appointment->id, $consultation->appointment_id);
        $this->post('/patients/'.$this->patient->id.'/consultations', $this->payload(['appointment_id' => $appointment->id]))->assertSessionHasErrors('appointment_id');
        $this->get('/patients/'.$this->patient->id.'/consultations/create?appointment_id='.$appointment->id)->assertRedirect('/consultations/'.$consultation->id);
        $this->update($consultation, ['appointment_id' => null])->assertSessionHasErrors('appointment_id');
    }

    public function test_appointment_of_other_doctor_or_patient_cannot_be_attached(): void
    {
        $other = User::factory()->create(['role' => 'doctor']);
        $appointment = Appointment::create(['doctor_id' => $other->id, 'patient_id' => $this->patient->id, 'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 09:30:00', 'status' => 'arrived']);
        $this->actingAs($this->doctor)->post('/patients/'.$this->patient->id.'/consultations', $this->payload(['appointment_id' => $appointment->id]))->assertForbidden();
        $this->get('/patients/'.$this->patient->id.'/consultations/create?appointment_id='.$appointment->id)->assertForbidden();
        $patient = Patient::create(['first_name' => 'Bob', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        $appointment->doctor_id = $this->doctor->id;
        $appointment->save();
        $this->post('/patients/'.$patient->id.'/consultations', $this->payload(['appointment_id' => $appointment->id]))->assertForbidden();
    }

    public function test_cancelled_scheduled_and_future_appointments_cannot_be_attached(): void
    {
        $this->actingAs($this->doctor);
        foreach (['scheduled', 'cancelled', 'absent'] as $status) {
            $appointment = Appointment::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 09:30:00', 'status' => $status]);
            $this->post('/patients/'.$this->patient->id.'/consultations', $this->payload(['appointment_id' => $appointment->id]))->assertSessionHasErrors('appointment_id');
        }
    }

    public function test_creation_page_and_patient_history_are_available_to_author(): void
    {
        $this->actingAs($this->doctor)->get('/patients/'.$this->patient->id.'/consultations/create')->assertInertia(fn (Assert $page) => $page->component('consultations/create', false)->where('patient.id', $this->patient->id));
        $consultation = $this->create();
        $this->get('/patients/'.$this->patient->id)->assertInertia(fn (Assert $page) => $page->has('consultations', 1)->where('consultations.0.id', $consultation->id)->missing('consultations.0.reason'));
    }

    public function test_account_with_consultations_cannot_be_deleted_and_no_delete_or_revision_update_exists(): void
    {
        $consultation = $this->create();
        $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->delete('/consultations/'.$consultation->id)->assertStatus(405);
        $this->patch('/consultation-revisions/1', ['snapshot' => []])->assertNotFound();
        $this->assertDatabaseCount('consultation_revisions', 1);
    }

    public function test_revision_history_is_paginated_and_keeps_older_snapshots(): void
    {
        $consultation = $this->create();
        for ($version = 2; $version <= 12; $version++) {
            $this->update($consultation, ['report' => 'Version fictive '.$version])->assertSessionHasNoErrors();
        }
        $this->get('/consultations/'.$consultation->id)->assertInertia(fn (Assert $page) => $page->has('revisions.data', 10)->where('revisions.data.0.version', 12));
        $this->get('/consultations/'.$consultation->id.'?page=2')->assertInertia(fn (Assert $page) => $page->has('revisions.data', 2)->where('revisions.data.1.snapshot.report', 'Compte rendu fictif'));
    }

    public function test_guests_are_redirected_and_suspended_doctors_cannot_write(): void
    {
        $this->get('/consultations')->assertRedirect('/login');
        $this->post('/patients/'.$this->patient->id.'/consultations', $this->payload())->assertRedirect('/login');
        $this->doctor->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->doctor)->post('/patients/'.$this->patient->id.'/consultations', $this->payload())->assertRedirect('/login');
        $this->assertDatabaseCount('consultations', 0);
    }
}
