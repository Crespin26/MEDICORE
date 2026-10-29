<?php

namespace Tests\Feature;

use App\Models\ClinicalShare;
use App\Models\Consultation;
use App\Models\MedicalDocument;
use App\Models\Patient;
use App\Models\PatientEvent;
use App\Models\User;
use App\Services\ClinicalBackup;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ClinicalBackupTest extends TestCase
{
    private string $root;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/medicore-backup-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/medical', 0700);
        touch($this->root.'/active.sqlite');
        $this->key = random_bytes(32);
        config(['backup.key' => 'base64:'.base64_encode($this->key), 'database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->root.'/active.sqlite', 'filesystems.disks.medical.root' => $this->root.'/medical']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        DB::statement('CREATE TABLE recovery_probe (id INTEGER PRIMARY KEY, report TEXT NOT NULL)');
        DB::table('recovery_probe')->insert(['report' => Crypt::encryptString('Rapport fictif à restaurer')]);
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        $consultation = new Consultation(['reason' => 'Motif fictif', 'report' => 'Consultation restaurable', 'vitals' => ['pulse' => '72'], 'consulted_at' => now()]);
        $consultation->doctor_id = $doctor->id;
        $consultation->patient_id = $patient->id;
        $consultation->save();
        $viewer = User::factory()->create(['role' => 'doctor']);
        PatientEvent::create(['patient_id' => $patient->id, 'actor_id' => $doctor->id, 'action' => 'sharing.granted', 'reason' => 'Motif de relais fictif', 'created_at' => now()]);
        ClinicalShare::create(['patient_id' => $patient->id, 'owner_id' => $doctor->id, 'viewer_id' => $viewer->id, 'reason' => 'Relais clinique fictif']);
        $content = random_bytes(100000);
        file_put_contents($this->root.'/medical/document.bin', Crypt::encryptString($content));
        MedicalDocument::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => 'Document fictif', 'original_name' => 'demo.pdf', 'path' => 'document.bin', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => strlen($content), 'sha256' => hash('sha256', $content)]);
        file_put_contents($this->root.'/.env', 'SECRET_MUST_NOT_BE_ARCHIVED');
        $this->maintenance(true);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function maintenance(bool $active): void
    {
        $mock = Mockery::mock(MaintenanceMode::class);
        $mock->shouldReceive('active')->andReturn($active);
        $this->app->instance(MaintenanceMode::class, $mock);
    }

    public function test_encrypted_backup_restores_database_and_medical_files_without_overwriting_live_data(): void
    {
        $before = hash_file('sha256', $this->root.'/active.sqlite');
        $this->artisan('medicore:backup', ['destination' => $this->root.'/backup.mcb'])->assertSuccessful();
        $archive = file_get_contents($this->root.'/backup.mcb');
        $this->assertStringNotContainsString('SQLite format', $archive);
        $this->assertStringNotContainsString('Rapport fictif', $archive);
        DB::table('recovery_probe')->update(['report' => Crypt::encryptString('Version après sauvegarde')]);
        $this->artisan('medicore:restore', ['archive' => $this->root.'/backup.mcb', 'destination' => $this->root.'/recovered'])->assertSuccessful();
        $pdo = new \PDO('sqlite:'.$this->root.'/recovered/database.sqlite');
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame('Consultation restaurable', Crypt::decryptString($pdo->query('SELECT report FROM consultations')->fetchColumn()));
        $this->assertSame('Motif de relais fictif', Crypt::decryptString($pdo->query('SELECT reason FROM patient_events')->fetchColumn()));
        $this->assertSame('Relais clinique fictif', Crypt::decryptString($pdo->query('SELECT reason FROM clinical_shares')->fetchColumn()));
        $this->assertSame('Rapport fictif à restaurer', Crypt::decryptString($pdo->query('SELECT report FROM recovery_probe')->fetchColumn()));
        $this->assertSame('Version après sauvegarde', Crypt::decryptString(DB::table('recovery_probe')->value('report')));
        $this->assertSame(file_get_contents($this->root.'/medical/document.bin'), file_get_contents($this->root.'/recovered/medical/document.bin'));
        $this->assertSame(100000, strlen(Crypt::decryptString(file_get_contents($this->root.'/recovered/medical/document.bin'))));
        $manifest = file_get_contents($this->root.'/recovered/manifest.json');
        $this->assertStringNotContainsString(config('app.key'), $manifest);
        $this->assertStringNotContainsString(base64_encode($this->key), $manifest);
        $this->assertFileDoesNotExist($this->root.'/recovered/.env');
        $this->assertNotSame($before, hash_file('sha256', $this->root.'/active.sqlite'));
        $this->assertSame(0600, fileperms($this->root.'/backup.mcb') & 0777);
        $this->assertSame(0700, fileperms($this->root.'/recovered') & 0777);
    }

    public function test_wrong_key_corruption_truncation_and_trailing_bytes_are_rejected_without_publishing(): void
    {
        $service = new ClinicalBackup;
        $service->create($this->root.'/backup.mcb');
        $archive = file_get_contents($this->root.'/backup.mcb');
        foreach (['corrupt' => substr_replace($archive, chr(ord($archive[90]) ^ 1), 90, 1), 'truncated' => substr($archive, 0, -8), 'appended' => $archive.'extra', 'wrong-key' => $archive] as $name => $content) {
            file_put_contents($this->root.'/'.$name.'.mcb', $content);
            config(['backup.key' => 'base64:'.base64_encode($name === 'wrong-key' ? random_bytes(32) : $this->key)]);
            try {
                $service->restore($this->root.'/'.$name.'.mcb', $this->root.'/'.$name);
                $this->fail('Archive invalide acceptée : '.$name);
            } catch (RuntimeException) {
                $this->assertDirectoryDoesNotExist($this->root.'/'.$name);
            }
        }
        $this->assertSame([], glob($this->root.'/medicore-recovery-*'));
    }

    public function test_existing_destinations_and_missing_application_keys_are_refused(): void
    {
        $service = new ClinicalBackup;
        $service->create($this->root.'/backup.mcb');
        $hash = hash_file('sha256', $this->root.'/backup.mcb');
        $this->artisan('medicore:backup', ['destination' => $this->root.'/backup.mcb'])->assertFailed();
        $this->assertSame($hash, hash_file('sha256', $this->root.'/backup.mcb'));
        $this->artisan('medicore:restore', ['archive' => $this->root.'/backup.mcb', 'destination' => $this->root.'/medical'])->assertFailed();
        $oldKey = app('encrypter')->getKey();
        $new = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $this->app->instance('encrypter', $new);
        Crypt::swap($new);
        $this->artisan('medicore:restore', ['archive' => $this->root.'/backup.mcb', 'destination' => $this->root.'/missing-keys'])->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root.'/missing-keys');
        $new->previousKeys([$oldKey]);
        $this->artisan('medicore:restore', ['archive' => $this->root.'/backup.mcb', 'destination' => $this->root.'/with-previous-key'])->assertSuccessful();
        $this->assertFileExists($this->root.'/with-previous-key/database.sqlite');
    }

    public function test_maintenance_independent_key_and_nonpublic_destinations_are_required(): void
    {
        $this->maintenance(false);
        $this->artisan('medicore:backup', ['destination' => $this->root.'/online.mcb'])->assertFailed();
        $this->assertFileDoesNotExist($this->root.'/online.mcb');
        $this->maintenance(true);
        foreach ([null, 'base64:'.base64_encode(app('encrypter')->getKey())] as $key) {
            config(['backup.key' => $key]);
            $this->artisan('medicore:backup', ['destination' => $this->root.'/invalid-key.mcb'])->assertFailed();
        }
        config(['backup.key' => 'base64:'.base64_encode($this->key)]);
        $this->artisan('medicore:backup', ['destination' => public_path('must-not-exist.mcb')])->assertFailed();
        $this->artisan('medicore:backup', ['destination' => $this->root.'/medical/must-not-exist.mcb'])->assertFailed();
    }

    public function test_missing_medical_document_prevents_a_partial_backup(): void
    {
        unlink($this->root.'/medical/document.bin');
        $this->artisan('medicore:backup', ['destination' => $this->root.'/incomplete.mcb'])->assertFailed();
        $this->assertFileDoesNotExist($this->root.'/incomplete.mcb');
    }

    public function test_authenticated_archive_with_path_traversal_is_rejected(): void
    {
        $zip = new ZipArchive;
        $zip->open($this->root.'/malicious.zip', ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['format' => 1, 'database_sha256' => '', 'app_key_fingerprints' => [hash('sha256', app('encrypter')->getKey())]]));
        $zip->addFromString('medical/../../escaped', 'untrusted');
        $zip->close();
        $output = fopen($this->root.'/malicious.mcb', 'xb');
        $method = new \ReflectionMethod(ClinicalBackup::class, 'encrypt');
        $method->invoke(new ClinicalBackup, $this->root.'/malicious.zip', $output, $this->key);
        fclose($output);
        $this->artisan('medicore:restore', ['archive' => $this->root.'/malicious.mcb', 'destination' => $this->root.'/malicious'])->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root.'/malicious');
        $this->assertFileDoesNotExist($this->root.'/escaped');
    }
}
