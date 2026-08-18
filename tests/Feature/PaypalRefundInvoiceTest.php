<?php

namespace Tests\Feature;

use App\Mail\RefundReceiptMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Billing\BillingDocumentService;
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

    public function test_paypal_refund_credits_wallet_and_does_not_call_paypal_api(): void
    {
        Mail::fake();
        Storage::fake('local');
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

        $applied = app(OrderRefundService::class)->cancelAndRefund($order->fresh(), 'Publisher rejected');
        $this->assertTrue($applied);

        $fresh = $order->fresh();
        $this->assertSame('refunded', $fresh->payment_status);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNull($fresh->paypal_refund_id);

        $wallet = Wallet::query()
            ->where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $this->assertNotNull($wallet);
        $this->assertEqualsWithDelta(100.40, (float) $wallet->balance, 0.01);

        Http::assertNothingSent();
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
