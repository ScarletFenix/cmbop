<?php

namespace Tests\Feature;

use App\Models\AdvertiserSpendBudget;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Advertiser\AdvertiserSpendService;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\Wallet\WalletOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvertiserSpendSystemTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Spend Test Site',
            'site_url' => 'https://spend-test.example',
            'domain' => 'spend-test.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'de',
            'language' => 'en',
            'category' => 'News',
            'price' => 100,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser, array $attrs = []): Order
    {
        $publisher = User::factory()->create();
        $site = $this->siteFor($publisher);

        return DB::transaction(function () use ($advertiser, $site, $attrs) {
            $order = Order::create(array_merge([
                'user_id' => $advertiser->id,
                'order_number' => 'ORD-'.uniqid(),
                'reference_code' => 'REF-'.uniqid(),
                'subtotal' => 100,
                'tax' => 0,
                'total_amount' => 100,
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'status' => 'processing',
                'paid_at' => now(),
            ], $attrs));

            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => (float) ($attrs['total_amount'] ?? 100),
                'additional_price' => 0,
                'content_link' => 'https://example.com/a.docx',
            ]);

            return $order->fresh(['items.site']);
        });
    }

    public function test_net_spend_subtracts_refunds_and_includes_card(): void
    {
        $user = $this->advertiser();
        $this->makeOrder($user, ['total_amount' => 100, 'payment_method' => 'wallet', 'status' => 'completed']);
        $this->makeOrder($user, ['total_amount' => 50, 'payment_method' => 'card', 'status' => 'completed']);
        $this->makeOrder($user, [
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'status' => 'cancelled',
            'payment_status' => 'refunded',
        ]);

        $summary = app(AdvertiserSpendService::class)->summary($user->id);

        $this->assertSame(190.0, $summary['gross']);
        $this->assertSame(40.0, $summary['refunded']);
        $this->assertSame(150.0, $summary['net']);
        $this->assertSame(150.0, $summary['spent']);
        $this->assertSame(0.0, $summary['in_progress']);
    }

    public function test_does_not_use_max_of_ledger_and_orders(): void
    {
        $user = $this->advertiser();
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => 1000,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        // Cart-style: one ledger purchase for 200, but two order lines of 100 each.
        WalletTransaction::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_PURCHASE,
            'direction' => 'debit',
            'amount' => 200,
            'bonus_amount' => 0,
            'balance_after' => 800,
            'bonus_balance_after' => 0,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'Marketplace purchase',
            'reference' => 'CART-REF',
        ]);

        $this->makeOrder($user, ['total_amount' => 100, 'status' => 'completed', 'reference_code' => 'CART-REF']);
        $this->makeOrder($user, ['total_amount' => 100, 'status' => 'completed', 'reference_code' => 'CART-REF']);

        $lifetime = app(AdvertiserSpendService::class)->lifetimeNet($user->id);
        $this->assertSame(200.0, $lifetime);
    }

    public function test_dim_candles_use_paid_at_and_move_to_spent_on_complete(): void
    {
        $user = $this->advertiser();
        $order = $this->makeOrder($user, [
            'total_amount' => 80,
            'status' => 'processing',
            'paid_at' => now()->subDay(),
        ]);

        $candles = app(AdvertiserSpendService::class)->candles($user->id, 'day');
        $this->assertTrue($candles['has_spend']);
        $day = collect($candles['series'])->firstWhere('in_progress', 80.0)
            ?? collect($candles['series'])->first();
        $this->assertNotNull($day);
        $this->assertSame(0.0, (float) $day['spent']);
        $this->assertSame(80.0, (float) $day['in_progress']);

        $order->update(['status' => 'completed', 'completed_at' => now()]);

        $candles2 = app(AdvertiserSpendService::class)->candles($user->id, 'day');
        $day2 = collect($candles2['series'])->firstWhere('key', $day['key']);
        $this->assertSame(80.0, (float) $day2['spent']);
        $this->assertSame(0.0, (float) $day2['in_progress']);
    }

    public function test_fill_gaps_pads_continuous_day_window(): void
    {
        $user = $this->advertiser();
        $this->makeOrder($user, [
            'total_amount' => 50,
            'status' => 'processing',
            'paid_at' => now()->subDays(2)->setTime(12, 0),
        ]);

        $from = now()->subDays(3)->startOfDay();
        $to = now()->endOfDay();

        $sparse = app(AdvertiserSpendService::class)->candles($user->id, 'day', [
            'from' => $from,
            'to' => $to,
        ]);
        $this->assertTrue($sparse['has_spend']);
        $this->assertLessThan(4, count($sparse['series']));

        $filled = app(AdvertiserSpendService::class)->candles($user->id, 'day', [
            'from' => $from,
            'to' => $to,
            'fill_gaps' => true,
        ]);
        $this->assertTrue($filled['has_spend']);
        $this->assertCount(4, $filled['series']);
        $this->assertSame($from->toDateString(), $filled['series'][0]['key']);
        $this->assertSame($to->toDateString(), $filled['series'][3]['key']);
        $this->assertSame(0.0, (float) $filled['series'][0]['spent']);
        $this->assertSame(0.0, (float) $filled['series'][0]['in_progress']);
    }

    public function test_breakdown_by_payment_method(): void
    {
        $user = $this->advertiser();
        $this->makeOrder($user, ['payment_method' => 'wallet', 'status' => 'completed', 'total_amount' => 70]);
        $this->makeOrder($user, ['payment_method' => 'card', 'status' => 'completed', 'total_amount' => 30]);

        $rows = app(AdvertiserSpendService::class)->breakdown($user->id, 'payment_method');
        $byKey = collect($rows)->keyBy('key');

        $this->assertSame(70.0, (float) $byKey['wallet']['net']);
        $this->assertSame(30.0, (float) $byKey['card']['net']);
    }

    public function test_analytics_page_and_export_routes(): void
    {
        $user = $this->advertiser();
        $this->makeOrder($user, ['status' => 'processing']);
        $this->makeOrder($user, ['status' => 'completed', 'total_amount' => 55]);

        $this->actingAs($user)
            ->get(route('advertiser.analytics', ['view' => 'day']))
            ->assertOk()
            ->assertSee('In progress', false)
            ->assertSee('Net spend', false);

        $this->actingAs($user)
            ->get(route('advertiser.analytics.export-csv'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('advertiser.analytics.export-pdf'))
            ->assertOk();
    }

    public function test_budget_warn_math_uses_committed(): void
    {
        $user = $this->advertiser();
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => 20,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        app(SpendBudgetService::class)->upsert($user, [
            'monthly_limit' => 100,
            'warn_at_percent' => 80,
            'low_balance_threshold' => 25,
            'notify_email' => false,
            'notify_bell' => false,
        ]);

        $this->makeOrder($user, ['total_amount' => 50, 'status' => 'processing']);
        $this->makeOrder($user, ['total_amount' => 35, 'status' => 'completed']);

        $status = app(SpendBudgetService::class)->status($user);
        $this->assertSame(85.0, $status['committed']);
        $this->assertTrue($status['over_warn']);
        $this->assertFalse($status['over_limit']);
        $this->assertTrue($status['low_balance']);
    }

    public function test_budget_notify_prefs_can_be_disabled(): void
    {
        $user = $this->advertiser();

        $this->actingAs($user)
            ->post(route('advertiser.analytics.budget'), [
                'monthly_limit' => 200,
                'warn_at_percent' => 80,
                'notify_email' => '0',
                'notify_bell' => '0',
            ])
            ->assertRedirect();

        $budget = AdvertiserSpendBudget::where('user_id', $user->id)->first();
        $this->assertNotNull($budget);
        $this->assertFalse($budget->notify_email);
        $this->assertFalse($budget->notify_bell);
    }

    public function test_refund_uses_max_of_order_and_ledger_not_xor(): void
    {
        $user = $this->advertiser();
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => 500,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->makeOrder($user, [
            'total_amount' => 100,
            'status' => 'cancelled',
            'payment_status' => 'refunded',
        ]);

        // Smaller ledger refund must not wipe the larger order refund via XOR.
        WalletTransaction::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_REFUND,
            'direction' => 'credit',
            'amount' => 25,
            'bonus_amount' => 0,
            'balance_after' => 525,
            'bonus_balance_after' => 0,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'Partial refund',
            'reference' => 'REF-PARTIAL',
        ]);

        $summary = app(AdvertiserSpendService::class)->summary($user->id);
        $this->assertSame(100.0, $summary['refunded']);
        $this->assertSame(0.0, $summary['net']);
    }

    public function test_featured_site_leftover_refund_does_not_inflate_marketplace_refunded(): void
    {
        $user = $this->advertiser();
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => 500,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->makeOrder($user, [
            'total_amount' => 100,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $site = Site::query()->firstOrFail();
        WalletTransaction::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_REFUND,
            'direction' => 'credit',
            'amount' => 29,
            'bonus_amount' => 0,
            'balance_after' => 529,
            'bonus_balance_after' => 0,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'Featured leftover',
            'reference' => 'PROMO-FEATURE-CREDIT-cs_test',
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
        ]);

        $summary = app(AdvertiserSpendService::class)->summary($user->id);
        $this->assertSame(0.0, $summary['refunded']);
        $this->assertSame(100.0, $summary['net']);
    }

    public function test_partial_clawback_appears_on_spend_export(): void
    {
        $user = $this->advertiser();
        $order = $this->makeOrder($user, [
            'total_amount' => 230,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $item = $order->items()->first();

        OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $user->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'Live URL was removed after the report window started.',
            'resolved_at' => now(),
            'advertiser_credited' => 115,
            'publisher_debited' => 100,
        ]);

        $rows = app(AdvertiserSpendService::class)->exportRows($user->id);
        $this->assertCount(1, $rows);
        $this->assertSame(230.0, $rows[0]['gross']);
        $this->assertSame(115.0, $rows[0]['refund']);
        $this->assertSame(115.0, $rows[0]['net']);
    }

    public function test_marketing_fee_scaffold_stays_disabled(): void
    {
        $this->assertFalse((bool) config('billing.advertiser_marketing.enabled'));
        $this->assertFalse((bool) config('billing.promo_feature.issue_invoice'));
    }

    public function test_wallet_lifetime_spending_uses_net_not_ledger_max(): void
    {
        $user = $this->advertiser();
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => 500,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->makeOrder($user, ['total_amount' => 100, 'status' => 'completed']);
        $this->makeOrder($user, [
            'total_amount' => 25,
            'status' => 'cancelled',
            'payment_status' => 'refunded',
        ]);

        $summary = app(WalletOverviewService::class)->summary($user->id, $wallet);
        $this->assertSame(100.0, (float) $summary['lifetime_spending']);
        $this->assertSame(25.0, (float) $summary['lifetime_spending_refunded']);
    }
}
