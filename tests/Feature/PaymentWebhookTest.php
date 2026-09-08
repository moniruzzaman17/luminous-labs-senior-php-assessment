<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentWebhookProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-08 10:00:00 UTC');
        config(['services.payment_provider.webhook_secret' => 'test-webhook-secret']);
    }

    public function test_valid_signed_payment_creates_one_order(): void
    {
        $response = $this->postSigned($this->payload());

        $response->assertCreated()->assertJson(['status' => 'created']);
        $this->assertDatabaseHas('orders', [
            'payment_id' => 'pay_1001',
            'amount_minor' => 1299,
            'currency' => 'gbp',
        ]);
    }

    public function test_repeated_event_is_acknowledged_without_another_order(): void
    {
        $payload = $this->payload();
        $this->postSigned($payload)->assertCreated();
        $this->postSigned($payload)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_different_event_ids_for_the_same_payment_remain_idempotent(): void
    {
        $first = $this->payload();
        $second = $first;
        $second['id'] = 'evt_retry_2';

        $this->postSigned($first)->assertCreated();
        $this->postSigned($second)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_invalid_or_stale_signatures_are_rejected(): void
    {
        $raw = json_encode($this->payload(), JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/payment-provider', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => 't='.now()->timestamp.',v1=bad',
        ], $raw)->assertUnauthorized();

        $this->postSigned($this->payload(), now()->subMinutes(10)->timestamp)->assertUnauthorized();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_missing_and_malformed_signature_headers_are_rejected(): void
    {
        $raw = json_encode($this->payload(), JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/payment-provider', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertUnauthorized();

        $this->call('POST', '/webhooks/payment-provider', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => 'not-a-signature-header',
        ], $raw)->assertUnauthorized();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_signature_is_checked_against_the_unmodified_raw_body(): void
    {
        $raw = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $signature = $this->signature($raw, now()->timestamp);
        $tampered = str_replace('1299', '1399', $raw);

        $this->call('POST', '/webhooks/payment-provider', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => $signature,
        ], $tampered)->assertUnauthorized();
    }

    public function test_signed_malformed_json_is_rejected_without_processing(): void
    {
        $raw = '{"id":';
        $timestamp = now()->timestamp;

        $this->call('POST', '/webhooks/payment-provider', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => $this->signature($raw, $timestamp),
        ], $raw)->assertOk()->assertJson(['status' => 'rejected']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_signed_non_object_json_is_permanently_rejected(): void
    {
        $raw = '"not-an-event-object"';
        $timestamp = now()->timestamp;

        $this->call('POST', '/webhooks/payment-provider', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => $this->signature($raw, $timestamp),
        ], $raw)->assertOk()->assertJson(['status' => 'rejected']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_other_signed_event_types_are_acknowledged_and_ignored(): void
    {
        $payload = $this->payload();
        $payload['type'] = 'payment.pending';

        $this->postSigned($payload)->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_validation_rejects_non_integer_money_and_missing_fields(): void
    {
        $payload = $this->payload();
        $payload['data']['object']['amount'] = 12.99;
        unset($payload['data']['object']['currency']);

        $this->postSigned($payload)->assertOk()->assertJson(['status' => 'rejected']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_conflicting_redelivery_is_not_mistaken_for_a_duplicate(): void
    {
        $this->postSigned($this->payload())->assertCreated();
        $changed = $this->payload();
        $changed['id'] = 'evt_changed';
        $changed['data']['object']['amount'] = 9999;

        $this->postSigned($changed)->assertOk()->assertJson(['status' => 'rejected']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_reused_event_id_for_a_different_payment_is_rejected(): void
    {
        $this->postSigned($this->payload())->assertCreated();
        $changed = $this->payload();
        $changed['data']['object']['id'] = 'pay_other';

        $this->postSigned($changed)->assertOk()->assertJson(['status' => 'rejected']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_unrelated_database_failure_is_retryable_and_later_retry_succeeds(): void
    {
        $this->mock(PaymentWebhookProcessor::class, function ($mock): void {
            $mock->shouldReceive('process')->once()->andThrow(new QueryException(
                'mysql',
                'insert into orders (...) values (...)',
                [],
                new PDOException('simulated connection loss'),
            ));
        });

        $this->postSigned($this->payload())
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30');
        $this->assertDatabaseCount('orders', 0);

        $this->app->bind(PaymentWebhookProcessor::class, fn () => new PaymentWebhookProcessor);
        $this->postSigned($this->payload())->assertCreated();
        $this->assertDatabaseCount('orders', 1);
    }

    private function payload(): array
    {
        return [
            'id' => 'evt_1001',
            'type' => 'payment.succeeded',
            'data' => ['object' => [
                'id' => 'pay_1001',
                'amount' => 1299,
                'currency' => 'GBP',
                'paid_at' => '2026-09-08T09:55:00+00:00',
            ]],
        ];
    }

    private function postSigned(array $payload, ?int $timestamp = null)
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp ??= now()->timestamp;

        return $this->call('POST', '/webhooks/payment-provider', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => $this->signature($raw, $timestamp),
        ], $raw);
    }

    private function signature(string $raw, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, 'test-webhook-secret');
    }
}
