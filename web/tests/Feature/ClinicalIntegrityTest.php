<?php

namespace Tests\Feature;

use App\Models\ClinicalShare;
use App\Models\Consultation;
use App\Models\MedicalAccessEvent;
use App\Models\MedicalDocument;
use App\Models\MedicalRecordEntry;
use App\Models\Patient;
use App\Models\User;
use App\Services\ConsultationRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClinicalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'clinic.timezone' => 'Africa/Porto-Novo']);
        $this->travelTo(CarbonImmutable::parse('2030-01-07 12:00:00', 'UTC'));
        Storage::fake('medical');
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        $this->actingAs($this->doctor);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['consulted_at' => '2030-01-07T10:00', 'reason' => 'Motif fictif', 'report' => "Compte rendu fictif\nDeuxième ligne conservée.", 'action' => 'validate'], $extra);
    }

    private function url(string $suffix): string
    {
        return '/patients/'.$this->patient->id.'/'.$suffix;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('fictif.pdf', "%PDF-1.4\n%%EOF");
    }

    public function test_invisible_text_cannot_validate_a_consultation_or_justify_a_correction(): void
    {
        $blank = "\u{00A0}\u{2003}\u{200B}\u{2060}\u{FEFF}";
        foreach (['reason', 'report'] as $field) {
            $this->post($this->url('consultations'), $this->payload([$field => $blank]))->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('consultations', 0);
        $this->post($this->url('consultations'), $this->payload())->assertSessionHasNoErrors();
        $consultation = Consultation::firstOrFail();
        $this->patch('/consultations/'.$consultation->id, $this->payload(['version' => 1, 'correction_reason' => $blank]))->assertSessionHasErrors('correction_reason');
        $this->assertSame(1, $consultation->fresh()->version);
        $this->assertSame(1, $consultation->revisions()->count());
        $this->assertSame($this->payload()['report'], $consultation->fresh()->report);
    }

    public function test_invisible_labels_titles_and_entry_correction_reasons_are_rejected_without_writes(): void
    {
        $blank = "\u{00A0}\u{200B}\u{2060}\u{FEFF}";
        $entry = ['kind' => 'allergy', 'label' => 'Information fictive', 'status' => 'active'];
        $this->post($this->url('medical-entries'), [...$entry, 'label' => $blank])->assertSessionHasErrors('label');
        $this->post($this->url('medical-documents'), ['title' => $blank, 'file' => $this->pdf()])->assertSessionHasErrors('title');
        $this->assertDatabaseCount('medical_record_entries', 0);
        $this->assertDatabaseCount('medical_documents', 0);
        $this->assertCount(0, Storage::disk('medical')->allFiles());
        $this->post($this->url('medical-entries'), $entry)->assertSessionHasNoErrors();
        $record = MedicalRecordEntry::firstOrFail();
        $this->patch($this->url('medical-entries/'.$record->id), [...$entry, 'version' => 1, 'correction_reason' => $blank])->assertSessionHasErrors('correction_reason');
        $this->assertSame(1, $record->fresh()->version);
        $this->assertSame(1, $record->revisions()->count());
    }

    public function test_invisible_sharing_reasons_cannot_grant_or_revoke_access(): void
    {
        $viewer = User::factory()->create(['role' => 'doctor']);
        $blank = str_repeat("\u{200B}", 10);
        $this->post($this->url('clinical-shares'), ['viewer_id' => $viewer->id, 'reason' => $blank])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('clinical_shares', 0);
        $this->post($this->url('clinical-shares'), ['viewer_id' => $viewer->id, 'reason' => 'Relais fictif autorisé'])->assertSessionHasNoErrors();
        $share = ClinicalShare::firstOrFail();
        $this->patch($this->url('clinical-shares/'.$share->id.'/revoke'), ['reason' => $blank])->assertSessionHasErrors('reason');
        $this->assertNull($share->fresh()->revoked_at);
    }

    public function test_recorder_rechecks_birth_date_when_given_an_outdated_patient_instance(): void
    {
        $old = $this->patient->fresh();
        $this->patient->update(['birth_date' => '2020-01-01']);
        try {
            app(ConsultationRecorder::class)->save($this->doctor, $old, $this->payload(['consulted_at' => '2010-01-07T10:00']));
            $this->fail('La naissance courante doit être vérifiée sous verrou.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('consulted_at', $error->errors());
        }
        $this->assertDatabaseCount('consultations', 0);
        $this->assertDatabaseCount('consultation_revisions', 0);
    }

    public function test_recorder_cannot_correct_a_consultation_using_another_patient(): void
    {
        $this->post($this->url('consultations'), $this->payload())->assertSessionHasNoErrors();
        $consultation = Consultation::firstOrFail();
        $other = Patient::create(['first_name' => 'Bob', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        try {
            app(ConsultationRecorder::class)->save($this->doctor, $other, $this->payload(['version' => 1, 'correction_reason' => 'Correction fictive']), $consultation->id);
            $this->fail('Le patient de la consultation doit rester invariant.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertSame(1, $consultation->fresh()->version);
        $this->assertDatabaseMissing('medical_access_events', ['patient_id' => $other->id]);
    }

    public function test_audit_failure_prevents_document_content_from_being_returned(): void
    {
        $this->post($this->url('medical-documents'), ['title' => 'Document fictif', 'file' => $this->pdf()])->assertSessionHasNoErrors();
        $document = MedicalDocument::firstOrFail();
        MedicalAccessEvent::creating(function () {
            throw new \RuntimeException('Échec fictif de journalisation');
        });
        try {
            $this->get($this->url('medical-documents/'.$document->id.'/download'))
                ->assertStatus(500)->assertDontSee('%PDF')->assertHeader('Cache-Control', 'no-store, private');
            $this->assertDatabaseMissing('medical_access_events', ['action' => 'document.downloaded']);
        } finally {
            MedicalAccessEvent::flushEventListeners();
        }
    }
}
