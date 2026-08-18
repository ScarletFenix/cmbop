<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaypalWebhookLog;
use App\Models\User;
use App\Services\CheckoutSchemaService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaypalPaymentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_have_paypal_payment_columns_after_migrate(): void
    {
        foreach (['paypal_order_id', 'paypal_capture_id', 'paypal_refund_id', 'paypal_response'] as $column) {
            $this->assertTrue(Schema::hasColumn('orders', $column), "orders.{$column} missing");
        }

        $this->assertTrue(Schema::hasTable('paypal_webhook_logs'));
        $this->assertTrue(Schema::hasColumn('paypal_webhook_logs', 'event_id'));
        $this->assertTrue(Schema::hasColumn('paypal_webhook_logs', 'processed'));
    }

    public function test_paypal_capture_id_is_unique(): void
    {
        $user = User::factory()->create();
        $this->makeOrder($user, 'CAP-DUP-1');

        $this->expectException(QueryException::class);
        $this->makeOrder($user, 'CAP-DUP-1');
    }

    public function test_blank_paypal_capture_ids_can_repeat(): void
    {
        $user = User::factory()->create();
        $first = $this->makeOrder($user, null);
        $second = $this->makeOrder($user, null);

        $this->assertNull($first->paypal_capture_id);
        $this->assertNull($second->paypal_capture_id);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_order_stores_paypal_ids_and_paid_via_helper(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'CAP-REL-1', [
            'payment_method' => 'paypal',
            'paypal_order_id' => 'PO-REL-1',
            'paypal_response' => ['id' => 'PO-REL-1', 'status' => 'COMPLETED'],
        ]);

        $fresh = $order->fresh();
        $this->assertTrue($fresh->paidViaPaypal());
        $this->assertSame('PO-REL-1', $fresh->paypal_order_id);
        $this->assertSame('CAP-REL-1', $fresh->paypal_capture_id);
        $this->assertSame('COMPLETED', $fresh->paypal_response['status'] ?? null);
    }

    public function test_webhook_log_event_id_is_unique(): void
    {
        PaypalWebhookLog::create([
            'event_id' => 'WH-EVT-DUP',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'payload' => ['id' => 'WH-EVT-DUP'],
            'processed' => false,
        ]);

        $this->expectException(QueryException::class);
        PaypalWebhookLog::create([
            'event_id' => 'WH-EVT-DUP',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'payload' => ['id' => 'WH-EVT-DUP'],
            'processed' => true,
        ]);
    }

    public function test_schema_healer_recreates_dropped_paypal_columns_and_log_table(): void
    {
        Schema::table('orders', function ($table) {
            $table->dropUnique(['paypal_capture_id']);
            $table->dropUnique(['paypal_refund_id']);
            $table->dropIndex(['paypal_order_id']);
        });
        Schema::table('orders', function ($table) {
            $table->dropColumn([
                'paypal_order_id',
                'paypal_capture_id',
                'paypal_refund_id',
                'paypal_response',
            ]);
        });
        Schema::dropIfExists('paypal_webhook_logs');

        $this->assertFalse(Schema::hasColumn('orders', 'paypal_capture_id'));
        $this->assertFalse(Schema::hasTable('paypal_webhook_logs'));

        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        $this->assertTrue(Schema::hasColumn('orders', 'paypal_order_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'paypal_capture_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'paypal_refund_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'paypal_response'));
        $this->assertTrue(Schema::hasTable('paypal_webhook_logs'));
        $this->assertTrue(PaypalWebhookLog::tableAvailable());

        $user = User::factory()->create();
        $this->makeOrder($user, 'CAP-HEAL-1');
        $this->expectException(QueryException::class);
        $this->makeOrder($user, 'CAP-HEAL-1');
    }

    public function test_webhook_log_survives_missing_table(): void
    {
        Schema::dropIfExists('paypal_webhook_logs');

        $this->assertFalse(PaypalWebhookLog::tableAvailable());
        $this->assertNull(PaypalWebhookLog::findAvailable(1));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOrder(User $user, ?string $captureId, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'order_number' => 'ORD-PP-'.uniqid(),
            'subtotal' => 10,
            'tax' => 0,
            'total_amount' => 10,
            'payment_method' => 'paypal',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paypal_capture_id' => $captureId,
        ], $overrides));
    }
}
