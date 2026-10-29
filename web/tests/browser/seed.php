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
// Two past visits permit the same linked-consultation flow in both viewports.
foreach ([8, 9] as $hour) {
    $start = CarbonImmutable::now('Africa/Porto-Novo')->subDay()->setTime($hour, 0)->utc();
    Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'arrived']);
}
BillableService::create(['code' => 'RECETTE', 'name' => 'Consultation fictive', 'currency' => 'XOF', 'price_minor' => 5000, 'active' => true]);
$consultation = new Consultation(['reason' => 'Motif privé du médecin auteur', 'report' => 'Compte rendu confidentiel de recette', 'vitals' => [], 'consulted_at' => now()->subHour()]);
$consultation->doctor_id = $doctor->id;
$consultation->patient_id = $patient->id;
$consultation->save();
echo "Base temporaire de recette prête.\n";
