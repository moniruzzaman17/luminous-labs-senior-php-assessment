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
}
