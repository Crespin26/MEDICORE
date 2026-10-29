<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationRevision;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsultationEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function consultation(): Consultation
    {
        $model = new Consultation(['reason' => 'Motif fictif confidentiel', 'observations' => null, 'report' => 'Rapport fictif', 'vitals' => ['pulse' => '72'], 'consulted_at' => now()]);
        $model->patient_id = Patient::create(['first_name' => 'Test', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01'])->id;
        $model->doctor_id = User::factory()->create(['role' => 'doctor'])->id;
        $model->save();

        return $model;
    }

    public function test_sensitive_content_and_correction_reasons_are_not_stored_in_cleartext(): void
    {
        $consultation = $this->consultation();
        $revision = ConsultationRevision::create(['consultation_id' => $consultation->id, 'author_id' => $consultation->doctor_id, 'author_name' => 'Médecin fictif', 'version' => 1, 'action' => 'created', 'snapshot' => ['report' => 'Rapport fictif'], 'correction_reason' => 'Précision confidentielle', 'created_at' => now()]);
        $raw = DB::table('consultations')->find($consultation->id);
        foreach (['reason', 'report', 'vitals'] as $field) {
            $this->assertNotSame($consultation->getAttribute($field), $raw->$field);
            $this->assertNotEmpty(Crypt::decryptString($raw->$field));
        }
        $this->assertNull($raw->observations);
        $this->assertSame(['pulse' => '72'], $consultation->fresh()->vitals);
        $rawRevision = DB::table('consultation_revisions')->find($revision->id);
        $this->assertStringNotContainsString('Rapport fictif', $rawRevision->snapshot);
        $this->assertStringNotContainsString('Précision confidentielle', $rawRevision->correction_reason);
        $this->assertSame('Précision confidentielle', $revision->fresh()->correction_reason);
    }

    public function test_migration_preserves_legacy_content_nulls_and_versions(): void
    {
        $consultation = $this->consultation();
        DB::table('consultations')->where('id', $consultation->id)->update(['reason' => 'Ancien motif', 'report' => 'Ancien rapport', 'observations' => null, 'vitals' => '{"pulse":"71"}']);
        $id = DB::table('consultation_revisions')->insertGetId(['consultation_id' => $consultation->id, 'author_id' => $consultation->doctor_id, 'author_name' => 'Fictif', 'version' => 1, 'action' => 'created', 'snapshot' => '{"report":"Ancien rapport"}', 'correction_reason' => 'Ancienne raison', 'created_at' => now()]);
        $migration = require database_path('migrations/2026_10_29_000001_encrypt_consultation_content.php');
        $migration->up();
        $fresh = $consultation->fresh();
        $this->assertSame('Ancien motif', $fresh->reason);
        $this->assertSame('Ancien rapport', $fresh->report);
        $this->assertNull($fresh->observations);
        $this->assertSame(['pulse' => '71'], $fresh->vitals);
        $this->assertSame(1, $fresh->version);
        $this->assertSame(['report' => 'Ancien rapport'], ConsultationRevision::findOrFail($id)->snapshot);
        $this->assertSame('Ancienne raison', ConsultationRevision::findOrFail($id)->correction_reason);
    }

    public function test_failed_legacy_conversion_rolls_back_every_content_update(): void
    {
        $consultation = $this->consultation();
        DB::table('consultations')->where('id', $consultation->id)->update(['reason' => 'Ancien motif', 'report' => 'Ancien rapport', 'observations' => null, 'vitals' => null]);
        $encrypter = app('encrypter');
        Crypt::shouldReceive('encryptString')->once()->with('Ancien motif')->andReturn($encrypter->encryptString('Ancien motif'));
        Crypt::shouldReceive('encryptString')->once()->with('Ancien rapport')->andThrow(new \RuntimeException('Échec simulé'));
        $migration = require database_path('migrations/2026_10_29_000001_encrypt_consultation_content.php');
        try {
            $migration->up();
            $this->fail('La conversion devait échouer.');
        } catch (\RuntimeException) {
            $this->assertSame('Ancien motif', DB::table('consultations')->value('reason'));
            $this->assertSame('Ancien rapport', DB::table('consultations')->value('report'));
        }
    }

    public function test_previous_key_keeps_existing_content_readable_after_rotation(): void
    {
        $consultation = $this->consultation();
        $oldKey = app('encrypter')->getKey();
        $new = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $new->previousKeys([$oldKey]);
        $this->app->instance('encrypter', $new);
        Crypt::swap($new);
        $this->assertSame('Rapport fictif', $consultation->fresh()->report);
        $consultation->update(['report' => 'Après rotation']);
        $this->assertSame('Après rotation', $new->decryptString(DB::table('consultations')->find($consultation->id)->report));
    }
}
