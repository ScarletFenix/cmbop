<?php

namespace Tests\Unit;

use App\Support\WebhookPayloadRedactor;
use Tests\TestCase;

class WebhookPayloadRedactorTest extends TestCase
{
    public function test_stripe_keeps_ids_type_and_scalar_routing_metadata(): void
    {
        $event = [
            'id' => 'evt_123',
            'type' => 'checkout.session.completed',
            'created' => 1_700_000_000,
            'data' => [
                'object' => [
                    'id' => 'cs_test_1',
                    'customer_email' => 'cardholder@example.com',
                    'customer_details' => ['email' => 'cardholder@example.com'],
                    'metadata' => [
                        'type' => 'order_payment',
                        'user_id' => '42',
                        'reference_code' => 'REF-1',
                        'deposit_id' => '9',
                        'site_id' => '7',
                        'amount' => '50.00',
                    ],
                ],
            ],
        ];

        $redacted = WebhookPayloadRedactor::stripe($event);

        $this->assertSame('evt_123', $redacted['id']);
        $this->assertSame('checkout.session.completed', $redacted['type']);
        $this->assertSame(1_700_000_000, $redacted['created']);
        $this->assertSame('cs_test_1', $redacted['object_id']);
        $this->assertSame([
            'type' => 'order_payment',
            'user_id' => '42',
            'reference_code' => 'REF-1',
            'deposit_id' => '9',
            'site_id' => '7',
        ], $redacted['metadata']);
        $this->assertArrayNotHasKey('data', $redacted);
        $this->assertSame(json_encode($redacted), json_encode($redacted));
        $this->assertStringNotContainsString('cardholder@example.com', json_encode($redacted));
        $this->assertStringNotContainsString('50.00', json_encode($redacted));
    }

    public function test_paypal_keeps_event_and_resource_ids_only(): void
    {
        $event = [
            'id' => 'WH-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'create_time' => '2026-01-01T00:00:00Z',
            'resource' => [
                'id' => 'CAP-1',
                'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
                'payer' => ['email_address' => 'buyer@example.com'],
            ],
        ];

        $redacted = WebhookPayloadRedactor::paypal($event);

        $this->assertSame([
            'id' => 'WH-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'create_time' => '2026-01-01T00:00:00Z',
            'resource_id' => 'CAP-1',
        ], $redacted);
        $this->assertStringNotContainsString('buyer@example.com', json_encode($redacted));
        $this->assertStringNotContainsString('25.00', json_encode($redacted));
    }
}
