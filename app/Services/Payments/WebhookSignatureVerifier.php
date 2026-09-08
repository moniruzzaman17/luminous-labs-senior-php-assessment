<?php

namespace App\Services\Payments;

final class WebhookSignatureVerifier
{
    public function verify(string $rawBody, ?string $header): bool
    {
        $secret = (string) config('services.payment_provider.webhook_secret');
        if ($secret === '' || $header === null) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key][] = $value;
            }
        }

        $timestamp = filter_var($parts['t'][0] ?? null, FILTER_VALIDATE_INT);
        $signatures = $parts['v1'] ?? [];
        $tolerance = (int) config('services.payment_provider.signature_tolerance_seconds', 300);

        if ($timestamp === false || abs(now()->timestamp - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($signatures as $signature) {
            if (ctype_xdigit($signature) && hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }
}
