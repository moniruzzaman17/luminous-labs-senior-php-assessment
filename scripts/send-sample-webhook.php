<?php

declare(strict_types=1);

$url = $argv[1] ?? 'http://127.0.0.1:8000/webhooks/payment-provider';
$secret = $argv[2] ?? 'local-demo-secret-change-me';
$payloadPath = $argv[3] ?? __DIR__.'/../examples/payment-succeeded.json';
$rawBody = file_get_contents($payloadPath);

if ($rawBody === false) {
    fwrite(STDERR, "Could not read payload file: {$payloadPath}".PHP_EOL);
    exit(1);
}

$timestamp = time();
$signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => [
            'Content-Type: application/json',
            "Payment-Signature: t={$timestamp},v1={$signature}",
        ],
        'content' => $rawBody,
        'ignore_errors' => true,
    ],
]);
$response = file_get_contents($url, false, $context);
$statusLine = $http_response_header[0] ?? 'HTTP response unavailable';

echo $statusLine.PHP_EOL.($response === false ? '' : $response).PHP_EOL;
exit($response === false ? 1 : 0);
