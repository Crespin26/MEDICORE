<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class AppointmentConcurrencyTest extends TestCase
{
    public function test_two_simultaneous_bookings_produce_one_booking_and_one_validation_conflict(): void
    {
        // The database file is private to the child processes, never the application's local database.
        $database = tempnam(sys_get_temp_dir(), 'medicore-race-');
        $options = json_decode(getenv('MEDICORE_TEST_PHP_OPTIONS') ?: '[]', true, flags: JSON_THROW_ON_ERROR);
        $environment = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'BCRYPT_ROUNDS' => '4'];
        $command = [PHP_BINARY, ...$options, base_path('tests/Support/appointment-race.php')];
        $workers = [];
        try {
            $setup = new Process([...$command, 'setup'], base_path(), $environment, timeout: 30);
            $setup->mustRun();
            $barrier = (string) (microtime(true) + 2);
            foreach ([1, 2] as $patient) {
                $worker = new Process([...$command, 'book', (string) $patient, $barrier], base_path(), $environment, timeout: 30);
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
            $this->assertSame(['BOOKED', 'CONFLICT'], $results);
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
