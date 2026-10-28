<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class PersonnelConcurrencyTest extends TestCase
{
    public function test_parallel_absence_and_booking_cannot_both_occupy_the_same_period(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'medicore-personnel-race-');
        $options = json_decode(getenv('MEDICORE_TEST_PHP_OPTIONS') ?: '[]', true, flags: JSON_THROW_ON_ERROR);
        $environment = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'BCRYPT_ROUNDS' => '4'];
        $command = [PHP_BINARY, ...$options, base_path('tests/Support/personnel-race.php')];
        $workers = [];
        try {
            (new Process([...$command, 'setup'], base_path(), $environment, timeout: 30))->mustRun();
            $barrier = (string) (microtime(true) + 2);
            foreach (['book', 'absence'] as $action) {
                $worker = new Process([...$command, $action, $barrier], base_path(), $environment, timeout: 30);
                $worker->start();
                $workers[] = $worker;
            }
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = trim($worker->getOutput());
            }
            $this->assertCount(1, array_filter($results, fn ($result) => $result === 'CONFLICT'));
            $this->assertCount(1, array_filter($results, fn ($result) => in_array($result, ['BOOKED', 'ABSENT'], true)));
            $count = new Process([...$command, 'count'], base_path(), $environment, timeout: 30);
            $count->mustRun();
            $this->assertSame('1', trim($count->getOutput()));
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
