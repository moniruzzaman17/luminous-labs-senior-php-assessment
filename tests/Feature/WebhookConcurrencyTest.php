<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebhookConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_attempts_create_exactly_one_order(): void
    {
        $connection = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
        ];
        $barrier = storage_path('framework/testing/webhook-concurrency-'.bin2hex(random_bytes(4)));
        $worker = base_path('tests/Support/concurrent_order_worker.php');

        $processes = collect(range(1, 4))->map(function (int $number) use ($environment, $barrier, $worker): Process {
            $process = new Process([PHP_BINARY, $worker, $barrier, (string) $number], base_path(), $environment);
            $process->setTimeout(15)->start();

            return $process;
        });

        touch($barrier);
        $processes->each(fn (Process $process) => $process->wait());
        @unlink($barrier);

        foreach ($processes as $process) {
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        $this->assertSame(1, DB::table('orders')->where('payment_id', 'pay_concurrent')->count());
    }
}
