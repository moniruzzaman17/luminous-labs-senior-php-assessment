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
            $reporter->report('Webhook rejected before processing.', ['reason' => 'invalid_signature']);

            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $reporter->report('Signed webhook permanently rejected.', ['reason' => 'malformed_json']);

            return response()->json(['status' => 'rejected']);
        }

        if (! is_array($event)) {
            $reporter->report('Signed webhook permanently rejected.', [
                'reason' => 'invalid_envelope',
                'invalid_fields' => ['body'],
            ]);

            return response()->json(['status' => 'rejected']);
        }

        $envelopeValidator = Validator::make($event, [
            'id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'type' => ['required', 'string', 'max:100'],
        ]);

        if ($envelopeValidator->fails()) {
            $reporter->report('Signed webhook permanently rejected.', [
                'reason' => 'invalid_envelope',
                'invalid_fields' => array_keys($envelopeValidator->errors()->messages()),
            ]);

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
            $reporter->report('Signed webhook permanently rejected.', [
                'event_id' => $event['id'],
                'reason' => 'invalid_payment',
                'invalid_fields' => array_keys($validator->errors()->messages()),
            ]);

            return response()->json(['status' => 'rejected']);
        }

        $validated = array_replace_recursive($envelopeValidator->validated(), $validator->validated());

        try {
            $result = $processor->process($validated);

            return response()->json(['status' => $result->value], $result->value === 'created' ? 201 : 200);
        } catch (PaymentConflictException) {
            $reporter->report('Signed webhook permanently rejected.', [
                'event_id' => $validated['id'],
                'payment_id' => $validated['data']['object']['id'],
                'reason' => 'identity_or_payment_conflict',
            ]);

            return response()->json(['status' => 'rejected']);
        } catch (Throwable $exception) {
            $reporter->report('Payment webhook processing failed.', [
                'event_id' => $validated['id'],
                'payment_id' => $validated['data']['object']['id'],
                'exception' => $exception::class,
            ]);

            return response()
                ->json(['message' => 'Webhook processing temporarily unavailable.'], 503)
                ->header('Retry-After', '30');
        }
    }
}
