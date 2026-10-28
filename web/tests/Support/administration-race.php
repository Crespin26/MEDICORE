<?php

use App\Http\Controllers\AdministrationController;
use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\DoctorAvailability;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-01-07 06:00:00', 'UTC'));
config(['clinic.timezone' => 'Africa/Porto-Novo']);
if ($argv[1] === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    User::factory()->create(['role' => 'admin']);
    $doctor = User::factory()->create(['role' => 'doctor']);
    Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
    DoctorAvailability::create(['doctor_id' => $doctor->id, 'starts_at' => '2030-01-07 07:00:00', 'ends_at' => '2030-01-07 16:00:00']);
    exit(0);
}
if ($argv[1] === 'count') {
    echo Appointment::count() + ClinicSetting::findOrFail(1)->version;
    exit(0);
}
while (microtime(true) < (float) $argv[2]) {
    usleep(1000);
}
try {
    if ($argv[1] === 'book') {
        app(AppointmentScheduler::class)->book(['patient_id' => 1, 'doctor_id' => 2], CarbonImmutable::parse('2030-01-07 08:00:00', 'UTC'), CarbonImmutable::parse('2030-01-07 08:30:00', 'UTC'));
        echo 'BOOKED';
    } else {
        $hours = [];
        for ($day = 1; $day <= 7; $day++) {
            $hours[$day] = ['enabled' => $day === 1, 'opens' => '08:00', 'closes' => '09:00'];
        }
        $request = Request::create('/administration/settings', 'PATCH', ['name' => 'Clinique fictive', 'currency' => 'XOF', 'enforce_hours' => true, 'opening_hours' => $hours, 'version' => 0, 'reason' => 'Horaires fictifs']);
        $request->setUserResolver(fn () => User::findOrFail(1));
        app(AdministrationController::class)->update($request, app(AppointmentScheduler::class));
        echo 'SETTINGS';
    }
} catch (ValidationException $error) {
    echo 'CONFLICT';
}
