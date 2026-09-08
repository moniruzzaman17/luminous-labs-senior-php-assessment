<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;
use Throwable;

final class WebhookFailureReporter
{
    public function reportSecurityRejection(): void
    {
        $this->write(
            channel: 'webhook_security',
            level: 'warning',
            message: 'Webhook security rejection.',
            context: [
                'category' => 'security_rejection',
                'reason' => 'invalid_signature',
            ],
        );
    }

    public function reportProcessingFailure(
        string $reason,
        ?string $eventId = null,
        ?string $paymentId = null,
        array $invalidFields = [],
        ?string $exceptionClass = null,
    ): void {
        $context = [
            'category' => 'provider_processing_failure',
            'reason' => $reason,
        ];

        if ($eventId !== null) {
            $context['event_id'] = $eventId;
        }
        if ($paymentId !== null) {
            $context['payment_id'] = $paymentId;
        }
        if ($invalidFields !== []) {
            $context['invalid_fields'] = $invalidFields;
        }
        if ($exceptionClass !== null) {
            $context['exception'] = $exceptionClass;
        }

        $this->write(
            channel: 'webhook_processing',
            level: 'error',
            message: 'Verified provider event requires attention.',
            context: $context,
        );
    }

    private function write(string $channel, string $level, string $message, array $context): void
    {
        try {
            Log::channel($channel)->{$level}($message, $context);
        } catch (Throwable $loggingFailure) {
            error_log(json_encode([
                'message' => $message,
                'context' => $context,
                'logging_failure' => $loggingFailure::class,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
