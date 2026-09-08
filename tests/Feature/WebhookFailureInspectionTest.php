<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class WebhookFailureInspectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.payment_provider.webhook_secret' => 'test-webhook-secret']);
    }

    public function test_invalid_signature_is_visible_only_as_a_sanitized_security_rejection(): void
    {
        $rawBody = '{"id":"evt_untrusted_must_not_be_logged","private":"raw-body-must-not-be-logged"}';
        $signature = 't=0,v1=supplied-signature-must-not-be-logged';

        $this->postRaw($rawBody, $signature)->assertUnauthorized();
        $this->flushWebhookLogs();

        [$exitCode, $securityOutput] = $this->failureOutput(['--type' => 'security', '--lines' => 50]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('WARNING', $securityOutput);
        $this->assertStringContainsString('security_rejection', $securityOutput);
        $this->assertStringContainsString('invalid_signature', $securityOutput);
        $this->assertStringNotContainsString($signature, $securityOutput);
        $this->assertStringNotContainsString('evt_untrusted_must_not_be_logged', $securityOutput);
        $this->assertStringNotContainsString('raw-body-must-not-be-logged', $securityOutput);

        [$exitCode, $processingOutput] = $this->failureOutput(['--lines' => 50]);
        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('invalid_signature', $processingOutput);
        $this->assertStringNotContainsString('evt_untrusted_must_not_be_logged', $processingOutput);
    }

    public function test_verified_malformed_event_appears_only_in_processing_failures(): void
    {
        $rawBody = '{"id":';

        $this->postRaw($rawBody, $this->signature($rawBody))
            ->assertOk()
            ->assertJson(['status' => 'rejected']);
        $this->flushWebhookLogs();

        [$exitCode, $processingOutput] = $this->failureOutput(['--lines' => 50]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('provider_processing_failure', $processingOutput);
        $this->assertStringContainsString('malformed_json', $processingOutput);
        $this->assertStringNotContainsString($rawBody, $processingOutput);

        [$exitCode, $securityOutput] = $this->failureOutput(['--type' => 'security', '--lines' => 50]);
        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('malformed_json', $securityOutput);
    }

    public function test_verified_temporary_failure_remains_visible_despite_security_noise(): void
    {
        foreach (range(1, 10) as $number) {
            $this->postRaw(
                "{\"id\":\"evt_untrusted_noise_{$number}\"}",
                "t=0,v1=untrusted-signature-{$number}",
            )->assertUnauthorized();
        }

        $this->mock(PaymentWebhookProcessor::class, function ($mock): void {
            $mock->shouldReceive('process')->once()->andThrow(
                new RuntimeException('internal SQL and credentials must not be logged'),
            );
        });

        $payload = [
            'id' => 'evt_verified_attention',
            'type' => 'payment.succeeded',
            'data' => ['object' => [
                'id' => 'pay_verified_attention',
                'amount' => 1299,
                'currency' => 'GBP',
                'paid_at' => '2026-09-08T09:55:00+00:00',
            ]],
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $this->signature($rawBody);

        $this->postRaw($rawBody, $signature)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30');
        $this->flushWebhookLogs();

        [$exitCode, $processingOutput] = $this->failureOutput(['--lines' => 1]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('ERROR', $processingOutput);
        $this->assertStringContainsString('temporary_processing_failure', $processingOutput);
        $this->assertStringContainsString('evt_verified_attention', $processingOutput);
        $this->assertStringContainsString('pay_verified_attention', $processingOutput);
        $this->assertStringContainsString('RuntimeException', $processingOutput);
        $this->assertStringNotContainsString('invalid_signature', $processingOutput);
        $this->assertStringNotContainsString($signature, $processingOutput);
        $this->assertStringNotContainsString('internal SQL and credentials must not be logged', $processingOutput);

        [$exitCode, $securityOutput] = $this->failureOutput(['--type' => 'security', '--lines' => 3]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('invalid_signature', $securityOutput);
        $this->assertStringNotContainsString('evt_verified_attention', $securityOutput);
        $this->assertStringNotContainsString('pay_verified_attention', $securityOutput);

        [$exitCode, $allOutput] = $this->failureOutput(['--type' => 'all', '--lines' => 1]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Processing webhook records:', $allOutput);
        $this->assertStringContainsString('Security webhook records:', $allOutput);
    }

    public function test_failure_command_rejects_an_unknown_type(): void
    {
        [$exitCode, $output] = $this->failureOutput(['--type' => 'unknown']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must be processing, security, or all', $output);
    }

    private function postRaw(string $rawBody, string $signature)
    {
        return $this->call('POST', '/webhooks/payment-provider', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => $signature,
        ], $rawBody);
    }

    private function signature(string $rawBody): string
    {
        $timestamp = now()->timestamp;

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, 'test-webhook-secret');
    }

    private function flushWebhookLogs(): void
    {
        foreach (['webhook_security', 'webhook_processing'] as $channel) {
            Log::channel($channel)->getLogger()->close();
            Log::forgetChannel($channel);
        }
    }

    private function failureOutput(array $options): array
    {
        $exitCode = Artisan::call('webhooks:failures', $options);

        return [$exitCode, Artisan::output()];
    }
}
