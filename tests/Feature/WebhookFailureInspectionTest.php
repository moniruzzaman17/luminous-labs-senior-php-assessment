<?php

namespace Tests\Feature;

use App\Services\Payments\WebhookFailureReporter;
use Tests\TestCase;

class WebhookFailureInspectionTest extends TestCase
{
    public function test_failure_command_displays_safe_context_without_secrets(): void
    {
        app(WebhookFailureReporter::class)->report('Payment webhook processing failed.', [
            'event_id' => 'evt_safe_for_review',
            'exception' => 'RuntimeException',
        ]);

        $this->artisan('webhooks:failures', ['--lines' => 50])
            ->expectsOutputToContain('evt_safe_for_review')
            ->doesntExpectOutputToContain('test-webhook-secret')
            ->doesntExpectOutputToContain('Payment-Signature')
            ->assertSuccessful();
    }

    public function test_invalid_signature_is_visible_without_recording_the_header(): void
    {
        $rawBody = '{"id":"evt_untrusted"}';
        $signature = 't=0,v1=do-not-record-this-signature';

        $this->call('POST', '/webhooks/payment-provider', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => $signature,
        ], $rawBody)->assertUnauthorized();

        $this->artisan('webhooks:failures', ['--lines' => 50])
            ->expectsOutputToContain('invalid_signature')
            ->doesntExpectOutputToContain($signature)
            ->doesntExpectOutputToContain('evt_untrusted')
            ->assertSuccessful();
    }
}
