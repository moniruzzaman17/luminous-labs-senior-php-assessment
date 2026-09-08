<?php

use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

while (! file_exists($argv[1])) {
    usleep(10_000);
}

$app->make(PaymentWebhookProcessor::class)->process([
    'id' => 'evt_concurrent_'.$argv[2],
    'type' => 'payment.succeeded',
    'data' => ['object' => [
        'id' => 'pay_concurrent',
        'amount' => 2500,
        'currency' => 'GBP',
        'paid_at' => '2026-09-08T09:55:00+00:00',
    ]],
]);
