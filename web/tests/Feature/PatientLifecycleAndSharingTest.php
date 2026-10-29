<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BillableService;
use App\Models\ClinicalShare;
use App\Models\Consultation;
use App\Models\Invoice;
use App\Models\MedicalDocument;
use App\Models\MedicalRecordEntry;
use App\Models\Patient;
use App\Models\PatientEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PatientLifecycleAndSharingTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): Patient
    {
        return Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
    }

    public function test_only_admin_can_confirm_a_distinct_homonym_and_reason_is_encrypted(): void
    {
        $original = $this->patient();
        $data = ['first_name' => $original->first_name, 'last_name' => $original->last_name, 'birth_date' => $original->birth_date->format('Y-m-d')];
        $secretary = User::factory()->create(['role' => 'secretary']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($secretary)->post('/patients', [...$data, 'distinct_reason' => 'Justification administrative confidentielle'])->assertSessionHasErrors('first_name');
        $this->assertArrayNotHasKey('distinct_reason', session('_old_input', []));
        $confirmed = [...$data, 'confirm_distinct' => true, 'distinct_reason' => 'Deux personnes distinctes vérifiées'];
        $this->post('/patients', $confirmed)->assertForbidden();
        $this->actingAs($admin)->post('/patients', [...$confirmed, 'distinct_reason' => ''])->assertSessionHasErrors('distinct_reason');
        $this->post('/patients', $confirmed)->assertRedirect();
        $this->assertDatabaseCount('patients', 2);
        $homonym = Patient::latest('id')->firstOrFail();
        $this->assertTrue($homonym->distinct_identity);
        $this->assertNull($homonym->getRawOriginal('identity_key'));
        $this->assertNotNull($original->fresh()->getRawOriginal('identity_key'));
        $this->assertSame('Deux personnes distinctes vérifiées', PatientEvent::firstOrFail()->reason);
        $this->assertStringNotContainsString('personnes distinctes', DB::table('patient_events')->value('reason'));
        // An ordinary contact correction preserves the approved exception.
        $this->actingAs($secretary)->patch('/patients/'.$homonym->id, [...$data, 'phone' => '12345678'])->assertRedirect();
        $this->assertSame('12345678', $homonym->fresh()->phone);
        $this->assertTrue($homonym->fresh()->distinct_identity);
        $this->patch('/patients/'.$original->id, [...$data, 'phone' => '87654321'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('87654321', $original->fresh()->phone);
        $this->assertNotNull($original->fresh()->getRawOriginal('identity_key'));
        $this->post('/patients', $data)->assertSessionHasErrors('first_name');
    }

    public function test_archive_is_admin_only_reversible_and_keeps_history(): void
    {
        $patient = $this->patient();
        $admin = User::factory()->create(['role' => 'admin']);
        $secretary = User::factory()->create(['role' => 'secretary']);
        $url = '/patients/'.$patient->id.'/lifecycle';
        $this->actingAs($secretary)->patch($url, ['archived' => true, 'reason' => 'Fiche inactive'])->assertForbidden();
        $this->actingAs($admin)->patch($url, ['archived' => true, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->patch($url, ['archived' => true, 'reason' => 'Fiche inactive'])->assertRedirect();
        $this->assertNotNull($patient->fresh()->archived_at);
        $this->get('/patients')->assertInertia(fn (Assert $page) => $page->has('patients.data', 0));
        $this->get('/patients?state=archived')->assertInertia(fn (Assert $page) => $page->has('patients.data', 1));
        $this->get('/patients/'.$patient->id)->assertOk();
        $this->patch('/patients/'.$patient->id, $patient->only('first_name', 'last_name', 'birth_date'))->assertSessionHasErrors('patient_id');
        $this->patch($url, ['archived' => false, 'reason' => 'Retour pour suivi'])->assertRedirect();
        $this->assertNull($patient->fresh()->archived_at);
        $this->assertDatabaseCount('patients', 1);
        $this->assertSame(['patient.archived', 'patient.reactivated'], PatientEvent::orderBy('id')->pluck('action')->all());
    }

    public function test_open_appointments_prevent_archive_and_archived_patient_cannot_be_booked(): void
    {
        $patient = $this->patient();
        $admin = User::factory()->create(['role' => 'admin']);
        $doctor = User::factory()->create(['role' => 'doctor']);
        $booking = Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30), 'status' => 'scheduled']);
        $this->actingAs($admin)->patch('/patients/'.$patient->id.'/lifecycle', ['archived' => true, 'reason' => 'Fiche inactive'])->assertSessionHasErrors('reason');
        $booking->update(['status' => 'cancelled']);
        $this->patch('/patients/'.$patient->id.'/lifecycle', ['archived' => true, 'reason' => 'Fiche inactive'])->assertRedirect();
        $this->post('/appointments', ['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'starts_at' => now()->addDays(2)->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(2)->addMinutes(30)->format('Y-m-d\TH:i')])->assertSessionHasErrors('patient_id');
        $this->get('/appointments/patients?search=Alice')->assertExactJson([]);
        $this->get('/billing/patients?search=Alice')->assertExactJson([]);
    }

    public function test_open_invoice_blocks_archive_and_archive_blocks_new_invoice(): void
    {
        $patient = $this->patient();
        $admin = User::factory()->create(['role' => 'admin']);
        $service = BillableService::create(['code' => 'TEST', 'name' => 'Acte fictif', 'currency' => 'XOF', 'price_minor' => 5000, 'active' => true]);
        $payload = ['patient_id' => $patient->id, 'token' => (string) Str::uuid(), 'lines' => [['service_id' => $service->id, 'quantity' => 1]]];
        $this->actingAs($admin)->post('/billing/invoices', $payload)->assertRedirect();
        $invoice = Invoice::firstOrFail();
        $url = '/patients/'.$patient->id.'/lifecycle';
        $this->patch($url, ['archived' => true, 'reason' => 'Fiche inactive'])->assertSessionHasErrors('reason');
        $this->post('/billing/invoices/'.$invoice->id.'/cancel', ['version' => $invoice->version, 'reason' => 'Annulation fictive'])->assertRedirect();
        $this->patch($url, ['archived' => true, 'reason' => 'Fiche inactive'])->assertRedirect();
        $this->post('/billing/invoices', [...$payload, 'token' => (string) Str::uuid()])->assertSessionHasErrors('patient_id');
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_archived_patient_cannot_receive_new_medical_content(): void
    {
        $patient = $this->patient();
        $patient->archived_at = now();
        $patient->save();
        $doctor = User::factory()->create(['role' => 'doctor']);
        $this->actingAs($doctor)->get('/patients/'.$patient->id.'/consultations/create')->assertSessionHasErrors('patient_id');
        $this->post('/patients/'.$patient->id.'/medical-entries', ['kind' => 'allergy', 'label' => 'Fictif', 'status' => 'active'])->assertSessionHasErrors('patient_id');
        $this->assertDatabaseCount('medical_record_entries', 0);
    }

    public function test_sharing_is_patient_scoped_read_only_revocable_and_audited(): void
    {
        $patient = $this->patient();
        $owner = User::factory()->create(['role' => 'doctor']);
        $viewer = User::factory()->create(['role' => 'doctor']);
        $consultation = new Consultation(['reason' => 'Motif partagé fictif', 'report' => 'Rapport partagé', 'consulted_at' => now()->subHour(), 'vitals' => []]);
        $consultation->patient_id = $patient->id;
        $consultation->doctor_id = $owner->id;
        $consultation->save();
        $entry = new MedicalRecordEntry(['kind' => 'allergy', 'label' => 'Allergie partagée', 'status' => 'active']);
        $entry->patient_id = $patient->id;
        $entry->doctor_id = $owner->id;
        $entry->version = 1;
        $entry->save();
        Storage::fake('medical');
        $bytes = 'document fictif';
        Storage::disk('medical')->put('fictif.enc', encrypt($bytes, false));
        $document = MedicalDocument::create(['patient_id' => $patient->id, 'doctor_id' => $owner->id, 'title' => 'Fictif', 'original_name' => 'fictif.pdf', 'path' => 'fictif.enc', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        $download = '/patients/'.$patient->id.'/medical-documents/'.$document->id.'/download';
        $this->actingAs($viewer)->get('/consultations/'.$consultation->id)->assertForbidden();
        $this->get($download)->assertForbidden();
        $this->actingAs($owner)->post('/patients/'.$patient->id.'/clinical-shares', ['viewer_id' => $viewer->id, 'reason' => 'Relais de suivi fictif'])->assertRedirect();
        $share = ClinicalShare::firstOrFail();
        $this->actingAs($viewer)->get('/consultations/'.$consultation->id)->assertInertia(fn (Assert $page) => $page->where('canUpdate', false)->where('consultation.report', 'Rapport partagé'));
        $this->get('/patients/'.$patient->id.'/medical-record')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->has('documents.data', 1));
        $this->get('/patients/'.$patient->id.'/medical-entries/'.$entry->id)->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));
        $this->get($download)->assertOk()->assertContent($bytes);
        $this->patch('/consultations/'.$consultation->id, [])->assertForbidden();
        $this->patch('/patients/'.$patient->id.'/medical-entries/'.$entry->id, [])->assertForbidden();
        $this->patch('/patients/'.$patient->id.'/clinical-shares/'.$share->id.'/revoke', ['reason' => 'Tentative tierce'])->assertForbidden();
        $other = $this->patientForAnotherIdentity();
        $unshared = $consultation->replicate();
        $unshared->patient_id = $other->id;
        $unshared->save();
        $this->get('/consultations/'.$unshared->id)->assertForbidden();
        $this->get('/patients/'.$other->id.'/medical-record')->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
        $third = User::factory()->create(['role' => 'doctor']);
        $this->post('/patients/'.$patient->id.'/clinical-shares', ['viewer_id' => $third->id, 'reason' => 'Partage de mes propres éléments'])->assertRedirect();
        $this->actingAs($third)->get('/consultations/'.$consultation->id)->assertForbidden();
        $this->actingAs($owner)->patch('/patients/'.$patient->id.'/clinical-shares/'.$share->id.'/revoke', ['reason' => 'Fin du relais fictif'])->assertRedirect();
        $this->actingAs($viewer)->get('/consultations/'.$consultation->id)->assertForbidden();
        $this->get($download)->assertForbidden();
        $this->get('/patients/'.$patient->id.'/medical-record')->assertInertia(fn (Assert $page) => $page->has('entries.data', 0)->has('documents.data', 0));
        $this->assertDatabaseHas('medical_access_events', ['actor_id' => $viewer->id, 'action' => 'document.downloaded']);
        $this->assertDatabaseHas('patient_events', ['action' => 'sharing.revoked']);
    }

    private function patientForAnotherIdentity(): Patient
    {
        return Patient::create(['first_name' => 'Autre', 'last_name' => 'Fictif', 'birth_date' => '1991-01-01']);
    }

    public function test_admin_cannot_grant_medical_access_and_invalid_recipients_are_rejected(): void
    {
        $patient = $this->patient();
        $admin = User::factory()->create(['role' => 'admin']);
        $doctor = User::factory()->create(['role' => 'doctor']);
        $url = '/patients/'.$patient->id.'/clinical-shares';
        $this->actingAs($admin)->post($url, ['viewer_id' => $doctor->id, 'reason' => 'Test de séparation'])->assertForbidden();
        $this->actingAs($doctor)->post($url, ['viewer_id' => $admin->id, 'reason' => 'Test de séparation'])->assertSessionHasErrors('viewer_id');
        $this->post($url, ['viewer_id' => $doctor->id, 'reason' => 'Test de séparation'])->assertSessionHasErrors('viewer_id');
        $inactive = User::factory()->create(['role' => 'doctor', 'status' => 'suspended']);
        $this->post($url, ['viewer_id' => $inactive->id, 'reason' => 'Test de séparation'])->assertSessionHasErrors('viewer_id');
        $this->assertDatabaseCount('clinical_shares', 0);
    }
}
