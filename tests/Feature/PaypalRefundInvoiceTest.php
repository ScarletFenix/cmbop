<?php

namespace Tests\Feature;

use App\Mail\RefundReceiptMail;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Billing\BillingDocumentService;
use App\Services\InAppNotificationService;
use App\Services\Orders\OrderRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaypalRefundInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'PayPal Advertiser',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function paidPaypalOrder(User $advertiser): Order
    {
        $publisher = User::factory()->create();
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'PayPal Invoice Site',
            'site_url' => 'https://paypal-invoice.example',
            'domain' => 'paypal-invoice.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        return DB::transaction(function () use ($advertiser, $site) {
            $order = Order::create([
                'user_id' => $advertiser->id,
                'order_number' => 'ORD-PP-'.uniqid(),
                'reference_code' => 'REF-PP-'.uniqid(),
                'subtotal' => 90.40,
                'tax' => 0,
                'total_amount' => 90.40,
                'payment_method' => 'paypal',
                'payment_status' => 'paid',
                'status' => 'pending',
                'paypal_order_id' => 'PO-INV-1',
                'paypal_capture_id' => 'CAP-INV-1',
                'paid_at' => now(),
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 90.40,
                'content_link' => 'https://example.com/article.docx',
            ]);

            return $order;
        })->fresh(['user', 'items']);
    }

    public function test_invoice_labels_paypal(): void
    {
        $this->assertSame('PayPal', Invoice::paymentMethodLabel('paypal'));
        $this->assertSame('PayPal', Invoice::paymentMethodLabel('PAYPAL'));
        $this->assertSame('Card', Invoice::paymentMethodLabel('card'));
        $this->assertSame('Wallet', Invoice::paymentMethodLabel('wallet'));
        $this->assertSame('Bank Transfer', Invoice::paymentMethodLabel('bank'));
        $this->assertSame('Wise', Invoice::paymentMethodLabel('wise'));
        $this->assertSame('Cryptocurrency', Invoice::paymentMethodLabel('crypto'));
    }

    public function test_paid_paypal_order_invoice_shows_paypal_method(): void
    {
        Mail::fake();
        Storage::fake('local');

        $order = $this->paidPaypalOrder($this->advertiser());
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->assertNotNull($invoice);
        $this->assertSame('paypal', $invoice->payment_method);
        $this->assertSame('PayPal', Invoice::paymentMethodLabel($invoice->payment_method));
    }

    public function test_paypal_refund_calls_api_and_does_not_credit_wallet(): void
    {
        Mail::fake();
        Storage::fake('local');
        config([
            'services.paypal.enabled' => true,
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
            'services.paypal.webhook_id' => 'WH-TEST-1',
            'services.paypal.base_url' => null,
        ]);
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
                'token_type' => 'Bearer',
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/payments/captures/CAP-INV-1/refund' => Http::response([
                'id' => 'RF-INV-1',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => '90.40'],
            ], 201),
        ]);

        $advertiser = $this->advertiser();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $order = $this->paidPaypalOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();
        app(BillingDocumentService::class)->handlePaymentPaid($order->fresh(['user', 'items']));

        $applied = app(OrderRefundService::class)->cancelAndRefund($order->fresh(), 'Publisher rejected');
        $this->assertTrue($applied);

        $fresh = $order->fresh();
        $this->assertSame('refunded', $fresh->payment_status);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('RF-INV-1', $fresh->paypal_refund_id);

        $wallet = Wallet::query()
            ->where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $this->assertNotNull($wallet);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->balance, 0.01);

        app(InAppNotificationService::class)->notifyRefundCredited($fresh, 90.40, 'Publisher rejected');
        $bell = InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('title', 'like', '%refunded to PayPal%')
            ->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('refunded to your PayPal account', (string) $bell->message);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/payments/captures/CAP-INV-1/refund'));
    }

    public function test_paypal_refund_fails_closed_when_unconfigured_with_capture(): void
    {
        Mail::fake();
        Storage::fake('local');
        config([
            'services.paypal.enabled' => false,
            'services.paypal.client_id' => '',
            'services.paypal.secret' => '',
        ]);
        Http::fake();

        $advertiser = $this->advertiser();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $order = $this->paidPaypalOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();
        app(BillingDocumentService::class)->handlePaymentPaid($order->fresh(['user', 'items']));

        try {
            app(OrderRefundService::class)->cancelAndRefund($order->fresh(), 'Publisher rejected');
            $this->fail('Expected PayPal refund to fail closed when a capture exists.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PayPal is not configured', $e->getMessage());
        }

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertNull($fresh->paypal_refund_id);

        $wallet = Wallet::query()
            ->where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $this->assertEqualsWithDelta(10.0, (float) $wallet->balance, 0.01);
        Http::assertNothingSent();
    }

    public function test_admin_refund_calls_paypal_and_skips_wallet_credit(): void
    {
        Mail::fake();
        Storage::fake('local');
        config([
            'services.paypal.enabled' => true,
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
            'services.paypal.webhook_id' => 'WH-TEST-1',
            'services.paypal.base_url' => null,
        ]);
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
                'token_type' => 'Bearer',
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/payments/captures/CAP-INV-1/refund' => Http::response([
                'id' => 'RF-ADMIN-1',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => '90.40'],
            ], 201),
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $advertiser = $this->advertiser();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 4,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $order = $this->paidPaypalOrder($advertiser);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
                'notes' => 'Admin PayPal refund',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $order->fresh();
        $this->assertSame('refunded', $fresh->payment_status);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('RF-ADMIN-1', $fresh->paypal_refund_id);
        $this->assertEqualsWithDelta(4.0, (float) Wallet::query()
            ->where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->value('balance'), 0.01);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/payments/captures/CAP-INV-1/refund'));
    }

    public function test_paypal_refund_generates_receipt_labeled_paypal(): void
    {
        Mail::fake();
        Storage::fake('local');

        $order = $this->paidPaypalOrder($this->advertiser());
        Invoice::query()->where('order_id', $order->id)->delete();
        $billing = app(BillingDocumentService::class);
        $billing->handlePaymentPaid($order->fresh(['user', 'items']));

        $order->payment_status = 'refunded';
        $order->saveQuietly();
        $refund = $billing->handlePaymentRefunded($order->fresh(['user', 'items']), 'Publisher rejected');

        $this->assertNotNull($refund);
        $this->assertSame(Invoice::TYPE_REFUND_RECEIPT, $refund->type);
        $this->assertSame('paypal', $refund->payment_method);
        $this->assertSame('PayPal', Invoice::paymentMethodLabel($refund->payment_method));
        Mail::assertQueued(RefundReceiptMail::class);
    }
}
