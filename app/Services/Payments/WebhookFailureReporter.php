<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;
use Throwable;

final class WebhookFailureReporter
{
    public function report(string $message, array $context = []): void
    {
        try {
            Log::channel('webhook')->error($message, $context);
        } catch (Throwable $loggingFailure) {
            error_log(json_encode([
                'message' => $message,
                'context' => $context,
                'logging_failure' => $loggingFailure::class,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
