<?php

use App\Models\Appointment;
use App\Models\BillableService;
use App\Models\Consultation;
use App\Models\DoctorAvailability;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;

// This is a CLI-only fixture, never a route or a general-purpose seeder.
$root = getenv('MEDICORE_E2E_DIR');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'e2e' || ! is_string($root) || ! is_dir($root) || ! str_starts_with(basename($root), 'medicore-e2e-') || getenv('DB_DATABASE') !== $root.'/database.sqlite') {
    throw new RuntimeException('Fixture limitée à la base temporaire de recette navigateur.');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
// Disable the hot-file path even if a developer has Vite running.
$app->make(Vite::class)->useHotFile($root.'/hot');
config(['filesystems.disks.medical.root' => $root.'/medical']);
touch($root.'/database.sqlite');
if ($kernel->call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Migration de recette échouée.');
}
foreach (['admin', 'doctor', 'other-doctor', 'secretary', 'cashier', 'staff', 'suspended'] as $role) {
    $user = new User(['name' => 'Recette '.$role, 'email' => $role.'@medicore.test', 'password' => 'Recette-Fictive-2026!']);
    $user->role = in_array($role, ['other-doctor', 'suspended'], true) ? 'doctor' : $role;
    $user->status = $role === 'suspended' ? 'suspended' : 'active';
    $user->save();
}
$patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Recette', 'birth_date' => '1990-01-01']);
$doctor = User::where('email', 'doctor@medicore.test')->firstOrFail();
$tomorrow = CarbonImmutable::now('Africa/Porto-Novo')->addDay()->startOfDay();
DoctorAvailability::create(['doctor_id' => $doctor->id, 'starts_at' => $tomorrow->setTime(8, 0)->utc(), 'ends_at' => $tomorrow->setTime(18, 0)->utc()]);
// One past visit per browser project keeps linked consultations independent.
foreach ([8, 9, 10, 11] as $hour) {
    $start = CarbonImmutable::now('Africa/Porto-Novo')->subDay()->setTime($hour, 0)->utc();
    Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'arrived']);
}
BillableService::create(['code' => 'RECETTE', 'name' => 'Consultation fictive', 'currency' => 'XOF', 'price_minor' => 5000, 'active' => true]);
$consultation = new Consultation(['reason' => 'Motif privé du médecin auteur', 'report' => 'Compte rendu confidentiel de recette', 'vitals' => [], 'consulted_at' => now()->subHour()]);
$consultation->doctor_id = $doctor->id;
$consultation->patient_id = $patient->id;
$consultation->save();
if (getenv('MEDICORE_BENCH_DATA') === '1') {
    $patients = [];
    for ($i = 0; $i < 1000; $i++) {
        $patients[] = ['first_name' => 'Patient '.$i, 'last_name' => 'Charge fictive', 'birth_date' => '1990-01-01', 'search_text' => 'patient '.$i.' charge fictive', 'identity_key' => Patient::identityKey('Patient '.$i, 'Charge fictive', '1990-01-01'), 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($patients, 100) as $chunk) {
        DB::table('patients')->insert($chunk);
    }
    $ids = Patient::pluck('id')->all();
    $appointments = [];
    for ($i = 0; $i < 10000; $i++) {
        $start = CarbonImmutable::now('Africa/Porto-Novo')->subDays(1 + intdiv($i, 200))->startOfDay()->addHours(8)->addMinutes(($i % 200) * 2)->utc();
        $appointments[] = ['patient_id' => $ids[$i % count($ids)], 'doctor_id' => $doctor->id, 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->addMinute()->format('Y-m-d H:i:s'), 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($appointments, 100) as $chunk) {
        DB::table('appointments')->insert($chunk);
    }
}
echo "Base temporaire de recette prête.\n";
