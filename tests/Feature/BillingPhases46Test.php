<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\WithdrawalPayoutStatementService;
use App\Services\Orders\OrderRefundService;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingPhases46Test extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function publisherWallet(User $user, float $balance = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function pendingWithdrawal(User $user, float $amount = 100): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'fee' => 5,
            'net_amount' => $amount - 5,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ]);
    }

    private function paidOrder(User $advertiser): Order
    {
        $publisher = User::factory()->create();
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Billing Phase Site',
            'site_url' => 'https://billing-phase.example',
            'domain' => 'billing-phase.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 100,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        return DB::transaction(function () use ($advertiser, $site) {
            $order = Order::create([
                'user_id' => $advertiser->id,
                'order_number' => 'ORD-'.uniqid(),
                'reference_code' => 'REF-'.uniqid(),
                'subtotal' => 115,
                'tax' => 0,
                'total_amount' => 115,
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'status' => 'pending',
                'paid_at' => now(),
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 115,
                'content_link' => 'https://example.com/article.docx',
            ]);

            return $order->fresh(['user', 'items']);
        });
    }

    public function test_mark_paid_issues_payout_statement_pdf(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 80);

        app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin, 'Paid');

        $statement = app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh());
        $this->assertNotNull($statement);
        $this->assertSame(Invoice::TYPE_WITHDRAWAL_PAYOUT, $statement->type);
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $statement->invoice_number);
        $this->assertSame(75.0, (float) $statement->total_amount);
        Storage::disk('local')->assertExists($statement->pdf_path);
    }

    public function test_reject_records_ledger_credit(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 40);

        app(ManualWithdrawalSettlementService::class)->reject($withdrawal, $admin, 'Bad IBAN');

        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_ADJUSTMENT,
            'direction' => 'credit',
            'amount' => 40,
            'reference' => 'WD-'.$withdrawal->id.'-refund',
        ]);
    }

    public function test_publisher_can_list_and_download_payout_docs(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 60);
        app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin);
        $statement = app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh());

        $this->actingAs($publisher)
            ->get(route('publisher.billing.index'))
            ->assertOk()
            ->assertSee($statement->invoice_number, false);

        $this->actingAs($publisher)
            ->get(route('publisher.billing.download', $statement))
            ->assertOk();
    }

    public function test_line_refund_amount_uses_order_total_for_single_item(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $order = $this->paidOrder($advertiser);

        $amount = app(OrderRefundService::class)->resolveLineRefundAmount($order, 99.0);
        $this->assertSame(115.0, $amount);
    }

    public function test_backfill_creates_missing_tax_invoice(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->makeUser('advertiser');
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();

        $result = app(BillingDocumentService::class)->backfillMissingTaxInvoices(10);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'type' => Invoice::TYPE_TAX_INVOICE,
        ]);
    }

    public function test_promo_feature_invoicing_stays_disabled(): void
    {
        $this->assertFalse((bool) config('billing.promo_feature.issue_invoice'));
        $this->assertNotEmpty(config('billing.promo_feature.exclusion_note'));
    }
}
