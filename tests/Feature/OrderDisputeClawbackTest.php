<?php

namespace Tests\Feature;

use App\Mail\DisputeClawbackPublisher;
use App\Mail\DisputeRefundAdvertiser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderDisputeClawbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Clawback Blog',
            'site_url' => 'https://clawback-blog.example',
            'domain' => 'clawback-blog.example',
            'example_url' => 'https://clawback-blog.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Clawback dispute site description. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeCompletedOrder(User $advertiser, Site $site, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-CLAW-'.random_int(1000, 9999),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'completed_at' => now()->subDays(1),
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'publisher_price' => 100,
            'platform_fee_amount' => 15,
            'additional_price' => 0,
            'live_url' => 'https://clawback-blog.example/live-post',
        ]);

        return $order->fresh(['items']);
    }

    private function publisherWallet(User $publisher, float $balance = 100): Wallet
    {
        $roleId = Wallet::publisherRoleId();

        return Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $roleId,
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function advertiserWallet(User $advertiser, float $balance = 0): Wallet
    {
        $roleId = Wallet::advertiserRoleId();

        return Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $roleId,
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 0,
            'currency' => 'EUR',
        ]);
    }

    public function test_advertiser_can_open_dispute_within_window(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);

        $response = $this->actingAs($advertiser)->postJson(
            route('advertiser.orders.report-link-removed', $order->id),
            ['reason' => 'The live article was deleted two days after completion.']
        );

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('order_item_disputes', [
            'order_id' => $order->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'opened_by' => $advertiser->id,
        ]);
    }

    public function test_advertiser_cannot_open_dispute_outside_window(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site, [
            'completed_at' => now()->subDays(45),
        ]);

        $response = $this->actingAs($advertiser)->postJson(
            route('advertiser.orders.report-link-removed', $order->id),
            ['reason' => 'The live article was deleted long after completion.']
        );

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertDatabaseCount('order_item_disputes', 0);
    }

    public function test_advertiser_cannot_dispute_non_completed_order(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site, ['status' => 'review']);

        $response = $this->actingAs($advertiser)->postJson(
            route('advertiser.orders.report-link-removed', $order->id),
            ['reason' => 'Trying to dispute before completion is invalid.']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('order_item_disputes', 0);
    }

    public function test_uphold_with_full_balance_debits_publisher_and_credits_advertiser(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $pubWallet = $this->publisherWallet($publisher, 100);
        $advWallet = $this->advertiserWallet($advertiser, 10);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live URL returns 404 after the publisher deleted the post.',
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Confirmed 404. Clawing back publisher payout and refunding advertiser.']
        );

        $response->assertOk()->assertJson(['success' => true]);

        $pubWallet->refresh();
        $advWallet->refresh();
        $order->refresh();
        $dispute->refresh();

        $this->assertSame(0.0, (float) $pubWallet->balance);
        $this->assertSame(0.0, (float) $pubWallet->debt_balance);
        $this->assertSame(125.0, (float) $advWallet->balance);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame('completed', $order->status);
        $this->assertSame(OrderItemDispute::STATUS_UPHELD, $dispute->status);
        $this->assertEquals(100.0, (float) $dispute->publisher_debited);
        $this->assertEquals(115.0, (float) $dispute->advertiser_credited);
        $this->assertEquals(0.0, (float) $dispute->debt_created);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $pubWallet->id,
            'type' => WalletTransaction::TYPE_TRANSFER_OUT,
            'direction' => 'debit',
            'amount' => 100,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $advWallet->id,
            'type' => WalletTransaction::TYPE_REFUND,
            'direction' => 'credit',
            'amount' => 115,
        ]);

        Mail::assertQueued(DisputeClawbackPublisher::class);
        Mail::assertQueued(DisputeRefundAdvertiser::class);

        // Full clawback with no debt — withdrawal still allowed.
        $this->assertFalse($pubWallet->hasDebt());
        $this->assertTrue($pubWallet->canWithdraw(0.01) === false); // balance 0
    }

    public function test_uphold_with_partial_balance_creates_debt_and_blocks_withdrawal(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $pubWallet = $this->publisherWallet($publisher, 40);
        $this->advertiserWallet($advertiser, 0);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Article deleted after publisher already withdrew most earnings.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Partial wallet balance; create debt for the remainder.']
        )->assertOk();

        $pubWallet->refresh();
        $dispute->refresh();

        $this->assertSame(0.0, (float) $pubWallet->balance);
        $this->assertSame(60.0, (float) $pubWallet->debt_balance);
        $this->assertEquals(40.0, (float) $dispute->publisher_debited);
        $this->assertEquals(60.0, (float) $dispute->debt_created);
        $this->assertTrue($pubWallet->hasDebt());

        // Credit some earnings later — still blocked by debt.
        $pubWallet->credit(80);
        $this->assertFalse($pubWallet->canWithdraw(10));

        $this->actingAs($publisher)->postJson(route('publisher.withdraw.request'), [
            'amount' => 20,
            'payment_method' => 'paypal',
            'paypal_email' => 'pub@example.com',
            'paypal_email_confirm' => 'pub@example.com',
            'details_confirmed' => '1',
        ])->assertStatus(422)->assertJsonPath('code', 'wallet_debt');
    }

    public function test_second_uphold_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $this->publisherWallet($publisher, 100);
        $this->advertiserWallet($advertiser, 0);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'First dispute for removed live link after completion.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'First uphold applies clawback successfully now.']
        )->assertOk();

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Second uphold must be rejected as already resolved.']
        )->assertStatus(422);
    }

    public function test_dismiss_leaves_balances_unchanged(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $pubWallet = $this->publisherWallet($publisher, 100);
        $advWallet = $this->advertiserWallet($advertiser, 5);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'False alarm — the article is still live on the site.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.dismiss', $dispute->id),
            ['admin_notes' => 'Checked live URL; article is still published correctly.']
        )->assertOk();

        $pubWallet->refresh();
        $advWallet->refresh();
        $order->refresh();
        $dispute->refresh();

        $this->assertSame(100.0, (float) $pubWallet->balance);
        $this->assertSame(5.0, (float) $advWallet->balance);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(OrderItemDispute::STATUS_DISMISSED, $dispute->status);
    }

    public function test_manual_approve_sets_completed_at(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $this->publisherWallet($publisher, 0);
        $this->advertiserWallet($advertiser, 0);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-APPR-'.random_int(1000, 9999),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'stripe',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'publisher_price' => 100,
            'platform_fee_amount' => 15,
            'live_url' => 'https://clawback-blog.example/live',
        ]);

        $this->actingAs($advertiser)->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_admin_can_clear_wallet_debt(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $wallet->update(['debt_balance' => 60]);

        $this->actingAs($admin)->post(
            route('admin.finance.wallets.clear-debt', $wallet),
            ['reason' => 'Publisher settled debt offline via invoice.']
        )->assertRedirect();

        $wallet->refresh();
        $this->assertSame(0.0, (float) $wallet->debt_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_ADJUSTMENT,
            'amount' => 60,
        ]);
    }
}
