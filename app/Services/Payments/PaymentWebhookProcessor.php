<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentWebhookProcessor
{
    public function process(array $event): WebhookResult
    {
        $payment = $event['data']['object'];

        try {
            DB::transaction(function () use ($event, $payment): void {
                Order::create([
                    'payment_id' => $payment['id'],
                    'provider_event_id' => $event['id'],
                    'amount_minor' => $payment['amount'],
                    'currency' => strtolower($payment['currency']),
                    'paid_at' => $payment['paid_at'],
                ]);
            }, 3);

            return WebhookResult::Created;
        } catch (UniqueConstraintViolationException) {
            // The unique index is the concurrency authority. The losing request
            // reaches this branch only after the winning transaction is durable.
            $existing = Order::where('payment_id', $payment['id'])->first();

            if ($existing !== null) {
                if (
                    $existing->amount_minor !== $payment['amount']
                    || $existing->currency !== strtolower($payment['currency'])
                    || $existing->paid_at->timestamp !== strtotime($payment['paid_at'])
                ) {
                    throw new PaymentConflictException('A payment was redelivered with conflicting order data.');
                }

                return WebhookResult::Duplicate;
            }

            if (Order::where('provider_event_id', $event['id'])->exists()) {
                throw new PaymentConflictException('A provider event ID was reused for a different payment.');
            }

            throw new RuntimeException('A uniqueness conflict occurred but no matching durable order exists.');
        }
    }
}
