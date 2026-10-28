<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BillingConcurrencyTest extends TestCase
{
    public function test_parallel_payments_cannot_overpay_and_duplicate_keys_return_the_same_receipt(): void
    {
        foreach (['different', 'same'] as $scenario) {
            $database = tempnam(sys_get_temp_dir(), 'medicore-billing-race-');
            $options = json_decode(getenv('MEDICORE_TEST_PHP_OPTIONS') ?: '[]', true, flags: JSON_THROW_ON_ERROR);
            $environment = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'BCRYPT_ROUNDS' => '4', 'CLINIC_CURRENCY' => 'XOF'];
            $command = [PHP_BINARY, ...$options, base_path('tests/Support/billing-race.php')];
            $workers = [];
            try {
                (new Process([...$command, 'setup'], base_path(), $environment, timeout: 30))->mustRun();
                $barrier = (string) (microtime(true) + 2);
                $token = (string) Str::uuid();
                for ($i = 0; $i < 2; $i++) {
                    $worker = new Process([...$command, 'pay', $scenario === 'same' ? '4000' : '6000', $scenario === 'same' ? $token : (string) Str::uuid(), $barrier], base_path(), $environment, timeout: 30);
                    $worker->start();
                    $workers[] = $worker;
                }
                $results = [];
                foreach ($workers as $worker) {
                    $worker->wait();
                    $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                    $results[] = trim($worker->getOutput());
                }
                sort($results);
                $this->assertSame($scenario === 'same' ? ['PAYMENT:1', 'PAYMENT:1'] : ['CONFLICT', 'PAYMENT:1'], $results);
                $count = new Process([...$command, 'count'], base_path(), $environment, timeout: 30);
                $count->mustRun();
                $this->assertSame(($scenario === 'same' ? '4000' : '6000').':1:partially_paid', trim($count->getOutput()));
            } finally {
                foreach ($workers as $worker) {
                    if ($worker->isRunning()) {
                        $worker->stop();
                    }
                }
                foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }
    }
}
