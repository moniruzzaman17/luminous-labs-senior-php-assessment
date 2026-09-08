<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentConflictException;
use App\Services\Payments\PaymentWebhookProcessor;
use App\Services\Payments\WebhookFailureReporter;
use App\Services\Payments\WebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Throwable;

final class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebhookSignatureVerifier $signatureVerifier,
        PaymentWebhookProcessor $processor,
        WebhookFailureReporter $reporter,
    ): JsonResponse {
        $rawBody = $request->getContent();

        if (! $signatureVerifier->verify($rawBody, $request->header('Payment-Signature'))) {
            $reporter->reportSecurityRejection();

            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $reporter->reportProcessingFailure('malformed_json');

            return response()->json(['status' => 'rejected']);
        }

        if (! is_array($event)) {
            $reporter->reportProcessingFailure('invalid_envelope', invalidFields: ['body']);

            return response()->json(['status' => 'rejected']);
        }

        $envelopeValidator = Validator::make($event, [
            'id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'type' => ['required', 'string', 'max:100'],
        ]);

        if ($envelopeValidator->fails()) {
            $reporter->reportProcessingFailure(
                'invalid_envelope',
                invalidFields: array_keys($envelopeValidator->errors()->messages()),
            );

            return response()->json(['status' => 'rejected']);
        }

        if ($event['type'] !== 'payment.succeeded') {
            return response()->json(['status' => 'ignored']);
        }

        $validator = Validator::make($event, [
            'data.object.id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'data.object.amount' => ['required', 'integer', 'min:1'],
            'data.object.currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'data.object.paid_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
        ]);

        if ($validator->fails()) {
            $reporter->reportProcessingFailure(
                'invalid_payment',
                eventId: $event['id'],
                invalidFields: array_keys($validator->errors()->messages()),
            );

            return response()->json(['status' => 'rejected']);
        }

        $validated = array_replace_recursive($envelopeValidator->validated(), $validator->validated());

        try {
            $result = $processor->process($validated);

            return response()->json(['status' => $result->value], $result->value === 'created' ? 201 : 200);
        } catch (PaymentConflictException) {
            $reporter->reportProcessingFailure(
                'identity_or_payment_conflict',
                eventId: $validated['id'],
                paymentId: $validated['data']['object']['id'],
            );

            return response()->json(['status' => 'rejected']);
        } catch (Throwable $exception) {
            $reporter->reportProcessingFailure(
                'temporary_processing_failure',
                eventId: $validated['id'],
                paymentId: $validated['data']['object']['id'],
                exceptionClass: $exception::class,
            );

            return response()
                ->json(['message' => 'Webhook processing temporarily unavailable.'], 503)
                ->header('Retry-After', '30');
        }
    }
}
