<?php

use App\Models\BillableService;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Patient;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($argv[1] === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    $actor = User::factory()->create(['role' => 'cashier']);
    $patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
    $act = BillableService::create(['code' => 'ACT-01', 'name' => 'Acte fictif', 'currency' => 'XOF', 'price_minor' => 10000, 'active' => true, 'version' => 1]);
    config(['clinic.currency' => 'XOF']);
    $service = app(BillingService::class);
    $invoice = $service->create($actor, ['patient_id' => $patient->id, 'token' => (string) Str::uuid(), 'lines' => [['service_id' => $act->id, 'quantity' => 1]]]);
    $service->issue($actor, $invoice->id, 1);
    exit(0);
}
if ($argv[1] === 'count') {
    $invoice = Invoice::firstOrFail();
    echo $invoice->paid_minor.':'.InvoicePayment::count().':'.$invoice->status;
    exit(0);
}
while (microtime(true) < (float) $argv[4]) {
    usleep(1000);
}
try {
    $payment = app(BillingService::class)->pay(User::firstOrFail(), 1, ['amount' => $argv[2], 'method' => 'cash', 'token' => $argv[3]]);
    echo 'PAYMENT:'.$payment->id;
} catch (ValidationException $error) {
    echo 'CONFLICT';
}
