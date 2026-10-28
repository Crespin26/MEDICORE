<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\MedicalAccessEvent;
use App\Models\MedicalDocument;
use App\Models\MedicalRecordEntry;
use App\Models\Patient;
use App\Models\User;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MedicalRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('medical');
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
    }

    private function url(string $suffix = 'medical-record'): string
    {
        return '/patients/'.$this->patient->id.'/'.$suffix;
    }

    private function data(array $extra = []): array
    {
        return array_replace(['kind' => 'allergy', 'label' => 'Allergie fictive', 'details' => 'Description fictive privée', 'occurred_on' => '2020-01-01', 'status' => 'active'], $extra);
    }

    private function entry(): MedicalRecordEntry
    {
        $this->actingAs($this->doctor)->post($this->url('medical-entries'), $this->data(['doctor_id' => 999, 'patient_id' => 999]))->assertRedirect()->assertSessionHasNoErrors();

        return MedicalRecordEntry::firstOrFail();
    }

    private function document(): MedicalDocument
    {
        $this->actingAs($this->doctor)->post($this->url('medical-documents'), ['title' => 'Document médical fictif', 'file' => UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF")])->assertRedirect()->assertSessionHasNoErrors();

        return MedicalDocument::firstOrFail();
    }

    public function test_entry_creation_is_encrypted_owned_and_audited(): void
    {
        $entry = $this->entry();
        $this->assertSame($this->doctor->id, $entry->doctor_id);
        $this->assertSame($this->patient->id, $entry->patient_id);
        $this->assertSame('Allergie fictive', $entry->label);
        $raw = DB::table('medical_record_entries')->first();
        $this->assertStringNotContainsString('Allergie fictive', $raw->label);
        $this->assertStringNotContainsString('Description fictive', $raw->details);
        $this->assertSame('Allergie fictive', Crypt::decryptString($raw->label));
        $this->assertStringNotContainsString('Allergie fictive', DB::table('medical_entry_revisions')->first()->snapshot);
        $this->assertSame('Allergie fictive', $entry->revisions()->first()->snapshot['label']);
        $this->assertDatabaseHas('medical_access_events', ['action' => 'entry.created', 'actor_id' => $this->doctor->id, 'subject_id' => $entry->id]);
    }

    public function test_correction_keeps_old_version_and_rejects_stale_or_unjustified_change(): void
    {
        $entry = $this->entry();
        $this->patch($this->url('medical-entries/'.$entry->id), $this->data(['version' => 1]))->assertSessionHasErrors('correction_reason');
        $this->patch($this->url('medical-entries/'.$entry->id), $this->data(['version' => 1, 'label' => 'Libellé corrigé', 'status' => 'resolved', 'correction_reason' => 'Précision fictive']))->assertSessionHasNoErrors();
        $this->assertSame(2, $entry->fresh()->version);
        $this->assertSame('Allergie fictive', $entry->revisions()->where('version', 1)->first()->snapshot['label']);
        $this->patch($this->url('medical-entries/'.$entry->id), $this->data(['version' => 1, 'correction_reason' => 'Ancien onglet']))->assertSessionHasErrors('version');
        $this->assertSame(2, $entry->revisions()->count());
    }

    public function test_record_filters_timeline_and_read_audit(): void
    {
        $entry = $this->entry();
        $document = $this->document();
        Consultation::forceCreate(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'consulted_at' => now(), 'reason' => 'Motif fictif', 'status' => 'draft']);
        $this->get($this->url().'?kind=allergy')->assertInertia(fn (Assert $page) => $page->component('medical-record/show', false)->has('entries.data', 1)->has('documents.data', 1)->has('timeline.data', 3)->missing('documents.data.0.path')->missing('documents.data.0.sha256'));
        $this->get($this->url().'?kind=treatment')->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
        $this->get($this->url('medical-entries/'.$entry->id))->assertInertia(fn (Assert $page) => $page->has('revisions.data', 1));
        $this->assertDatabaseHas('medical_access_events', ['action' => 'record.view']);
        $this->assertDatabaseHas('medical_access_events', ['action' => 'entry.view']);
    }

    public function test_other_doctor_sees_no_content_and_cannot_read_update_or_download_direct_ids(): void
    {
        $entry = $this->entry();
        $document = $this->document();
        $this->actingAs(User::factory()->create(['role' => 'doctor']));
        $this->get($this->url())->assertInertia(fn (Assert $page) => $page->has('entries.data', 0)->has('documents.data', 0)->has('timeline.data', 0));
        $this->get($this->url('medical-entries/'.$entry->id))->assertForbidden();
        $this->patch($this->url('medical-entries/'.$entry->id), $this->data(['version' => 1, 'correction_reason' => 'Intrus']))->assertForbidden();
        $this->get($this->url('medical-documents/'.$document->id.'/download'))->assertForbidden();
        $this->get($this->url('medical-record/audit'))->assertInertia(fn (Assert $page) => $page->where('events.total', 2));
    }

    public function test_non_clinical_roles_are_blocked_from_every_endpoint(): void
    {
        $entry = $this->entry();
        $document = $this->document();
        foreach (['admin', 'secretary', 'cashier', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach (['medical-record', 'medical-record/audit', 'medical-entries/'.$entry->id, 'medical-documents/'.$document->id.'/download'] as $suffix) {
                $this->get($this->url($suffix))->assertForbidden();
            }
            $this->post($this->url('medical-entries'), $this->data())->assertForbidden();
            $this->patch($this->url('medical-entries/'.$entry->id), $this->data())->assertForbidden();
            $this->post($this->url('medical-documents'))->assertForbidden();
        }
    }

    public function test_download_decrypts_integrity_checked_attachment_and_records_access(): void
    {
        $document = $this->document();
        $ciphertext = Storage::disk('medical')->get($document->path);
        $this->assertStringNotContainsString('%PDF', $ciphertext);
        $plain = Crypt::decryptString($ciphertext);
        $response = $this->get($this->url('medical-documents/'.$document->id.'/download'))->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($plain, $response->getContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('medical_access_events', ['action' => 'document.downloaded', 'subject_id' => $document->id]);
    }

    public function test_missing_and_corrupted_documents_do_not_download(): void
    {
        $document = $this->document();
        $document->forceFill(['sha256' => str_repeat('0', 64)])->save();
        $this->get($this->url('medical-documents/'.$document->id.'/download'))->assertStatus(409);
        Storage::disk('medical')->put($document->path, 'corrupted-ciphertext');
        $this->get($this->url('medical-documents/'.$document->id.'/download'))->assertStatus(409);
        Storage::disk('medical')->delete($document->path);
        $this->get($this->url('medical-documents/'.$document->id.'/download'))->assertNotFound();
        $this->assertDatabaseMissing('medical_access_events', ['action' => 'document.downloaded']);
    }

    public function test_files_reject_html_svg_executable_mismatch_and_oversize(): void
    {
        $this->actingAs($this->doctor);
        foreach ([UploadedFile::fake()->createWithContent('attack.html', '<html>unsafe</html>'), UploadedFile::fake()->createWithContent('attack.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), UploadedFile::fake()->createWithContent('attack.php', '<?php echo 1;'), UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'), UploadedFile::fake()->createWithContent('fake.jpg', "%PDF-1.4\n%%EOF")] as $file) {
            $this->post($this->url('medical-documents'), ['title' => 'Test', 'file' => $file])->assertSessionHasErrors('file');
        }
        $this->assertDatabaseCount('medical_documents', 0);
        $this->assertCount(0, Storage::disk('medical')->allFiles());
    }

    public function test_png_upload_is_accepted(): void
    {
        $this->actingAs($this->doctor)->post($this->url('medical-documents'), ['title' => 'Image fictive', 'file' => UploadedFile::fake()->createWithContent('scan.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aYioAAAAASUVORK5CYII='))])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('medical_documents', ['mime_type' => 'image/png']);
    }

    public function test_upload_failure_in_audit_rolls_back_metadata_and_removes_ciphertext(): void
    {
        MedicalAccessEvent::creating(function () {
            throw new \RuntimeException('Simulated audit failure');
        });
        $this->actingAs($this->doctor)->post($this->url('medical-documents'), ['title' => 'Test', 'file' => UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\n%%EOF")])->assertStatus(500);
        $this->assertDatabaseCount('medical_documents', 0);
        $this->assertCount(0, Storage::disk('medical')->allFiles());
        MedicalAccessEvent::flushEventListeners();
    }

    public function test_patient_binding_is_checked_on_entry_and_document(): void
    {
        $entry = $this->entry();
        $document = $this->document();
        $other = Patient::create(['first_name' => 'Bob', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        $this->get('/patients/'.$other->id.'/medical-entries/'.$entry->id)->assertNotFound();
        $this->get('/patients/'.$other->id.'/medical-documents/'.$document->id.'/download')->assertNotFound();
    }

    public function test_validation_and_session_do_not_keep_clinical_text(): void
    {
        $this->actingAs($this->doctor)->post($this->url('medical-entries'), $this->data(['label' => '', 'kind' => 'invalid', 'details' => 'Secret fictif']))->assertSessionHasErrors(['kind', 'label']);
        $this->assertArrayNotHasKey('details', session()->getOldInput());
        $this->assertArrayNotHasKey('label', session()->getOldInput());
        $this->assertDatabaseCount('medical_record_entries', 0);
    }

    public function test_audit_contains_no_clinical_contents_and_prevents_account_deletion(): void
    {
        $this->entry();
        $event = MedicalAccessEvent::firstOrFail();
        $this->assertStringNotContainsString('Allergie fictive', $event->toJson());
        $this->assertStringNotContainsString('Description fictive', $event->toJson());
        $this->get($this->url('medical-record/audit'))->assertInertia(fn (Assert $page) => $page->component('medical-record/audit', false));
        $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertNotNull($this->doctor->fresh());
    }

    public function test_consultation_reads_and_writes_are_recorded_without_clinical_text(): void
    {
        $this->actingAs($this->doctor)->post($this->url('consultations'), [
            'consulted_at' => ClinicTime::local(CarbonImmutable::now('UTC')),
            'reason' => 'Motif privé fictif', 'report' => 'Note fictive', 'action' => 'draft',
        ])->assertSessionHasNoErrors();
        $consultation = Consultation::firstOrFail();
        $this->get('/consultations')->assertOk();
        $this->get('/consultations/'.$consultation->id)->assertOk();
        $this->get('/patients/'.$this->patient->id)->assertOk();
        foreach (['consultation.created', 'consultations.list', 'consultation.view', 'consultations.summary'] as $action) {
            $this->assertDatabaseHas('medical_access_events', ['action' => $action, 'patient_id' => $this->patient->id]);
        }
        $this->assertStringNotContainsString('Motif privé fictif', MedicalAccessEvent::all()->toJson());
    }

    public function test_audit_failure_blocks_record_read(): void
    {
        config(['app.debug' => false]);
        $this->entry();
        MedicalAccessEvent::creating(function () {
            throw new \RuntimeException('Simulated audit failure');
        });
        $this->get($this->url())->assertStatus(500)->assertDontSee('Allergie fictive');
        MedicalAccessEvent::flushEventListeners();
    }

    public function test_no_deletion_route_and_guest_cannot_access_record(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $entry = $this->entry();
        $this->delete($this->url('medical-entries/'.$entry->id))->assertStatus(405);
        $this->delete('/medical-access-events/1')->assertNotFound();
    }
}
