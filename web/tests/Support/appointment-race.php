<?php

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

// Isolated process helper used only by AppointmentConcurrencyTest.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-01-07 06:00:00', 'UTC'));
if ($argv[1] === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    $doctor = User::factory()->create(['role' => 'doctor']);
    foreach (['Alice', 'Bob'] as $name) {
        Patient::create(['first_name' => $name, 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
    }
    DoctorAvailability::create(['doctor_id' => $doctor->id, 'starts_at' => '2030-01-07 07:00:00', 'ends_at' => '2030-01-07 16:00:00']);
    exit(0);
}
if ($argv[1] === 'count') {
    echo Appointment::count();
    exit(0);
}
while (microtime(true) < (float) $argv[3]) {
    usleep(1000);
}
try {
    app(AppointmentScheduler::class)->book(
        ['patient_id' => (int) $argv[2], 'doctor_id' => 1],
        CarbonImmutable::parse('2030-01-07 08:00:00', 'UTC'),
        CarbonImmutable::parse('2030-01-07 08:30:00', 'UTC'),
    );
    echo 'BOOKED';
} catch (ValidationException $error) {
    echo 'CONFLICT';
}
