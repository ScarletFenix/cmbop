<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\InAppNotificationService;
use App\Services\OrderPaymentService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BellNotificationEventsTest extends TestCase
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
            'site_name' => 'Bell Blog',
            'site_url' => 'https://bell-blog.example',
            'domain' => 'bell-blog.example',
            'example_url' => 'https://bell-blog.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Bell notification site description. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser, Site $site, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-BELL-'.random_int(1000, 9999),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'additional_price' => 0,
        ]);

        return $order->fresh(['items']);
    }

    public function test_pending_manual_payment_does_not_ping_publisher_bell(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $order = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        app(InAppNotificationService::class)->notifyOrderCreated($order);

        $this->assertDatabaseMissing('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_ORDER_CREATED,
        ]);

        $this->assertDatabaseHas('order_activities', [
            'order_id' => $order->id,
            'event' => 'order.created',
        ]);
    }

    public function test_paid_order_notifies_publisher_and_advertiser_once(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'card',
            'payment_status' => 'paid',
        ]);

        app(OrderPaymentService::class)->notifyPublishersOfPaidOrders([$order]);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_ORDER_CREATED,
            'related_id' => $order->id,
        ]);

        $advertiserNotes = InAppNotification::where('user_id', $advertiser->id)
            ->where('type', InAppNotificationService::TYPE_PAYMENT_RECEIVED)
            ->get();
        $this->assertCount(1, $advertiserNotes);
        $this->assertStringContainsString('placed', strtolower($advertiserNotes->first()->title));
        $this->assertStringContainsString('115.00', $advertiserNotes->first()->message);
    }

    public function test_card_payment_failure_creates_pay_again_bell_once(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $ref = 'REF-FAIL-'.random_int(1000, 9999);

        $orderA = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'paid_at' => null,
            'reference_code' => $ref,
            'order_number' => '111111',
        ]);
        $orderB = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'paid_at' => null,
            'reference_code' => $ref,
            'order_number' => '222222',
        ]);

        app(OrderPaymentService::class)->markOrdersFailedFromReference($ref, 'Checkout expired');

        $this->assertSame('failed', $orderA->fresh()->payment_status);
        $this->assertSame('failed', $orderB->fresh()->payment_status);

        $failed = InAppNotification::where('user_id', $advertiser->id)
            ->where('type', InAppNotificationService::TYPE_PAYMENT_FAILED)
            ->get();
        $this->assertCount(1, $failed);
        $this->assertSame('Pay again', $failed->first()->action_label);
        $this->assertStringContainsString('payment_status=failed', $failed->first()->action_url);
        $this->assertStringContainsString('Checkout expired', (string) $failed->first()->message);
    }

    public function test_publisher_reject_sends_refund_credited_bell(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $advertiserRoleId = Wallet::advertiserRoleId();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advertiserRoleId,
            'balance' => 0,
            'reserved_balance' => 115,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $order = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $item = $order->items()->first();

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'The article topic does not fit our editorial guidelines.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_ORDER_REJECTED,
            'related_id' => $order->id,
        ]);

        $refund = InAppNotification::where('user_id', $advertiser->id)
            ->where('type', InAppNotificationService::TYPE_PAYMENT_RECEIVED)
            ->where('title', 'like', '%back to your wallet%')
            ->first();
        $this->assertNotNull($refund);
        $this->assertStringContainsString('€115.00', $refund->message);
    }

    public function test_admin_marks_manual_payment_paid_notifies_publisher_and_advertiser(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $order = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_ORDER_CREATED,
            'related_id' => $order->id,
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_PAYMENT_RECEIVED,
            'related_id' => $order->id,
        ]);
    }

    public function test_admin_refund_credits_wallet_and_bell(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $advertiserRoleId = Wallet::advertiserRoleId();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advertiserRoleId,
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $order = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'processing',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
                'notes' => 'Duplicate charge',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertEquals(125.0, (float) $wallet->fresh()->balance);

        $refund = InAppNotification::where('user_id', $advertiser->id)
            ->where('type', InAppNotificationService::TYPE_PAYMENT_RECEIVED)
            ->where('title', 'like', '%back to your wallet%')
            ->first();
        $this->assertNotNull($refund);
        $this->assertStringContainsString('€115.00', $refund->message);
        $this->assertStringContainsString('Duplicate charge', (string) $refund->message);
    }

    public function test_deposit_approve_and_reject_bell_notifications(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advertiserRoleId = Wallet::advertiserRoleId();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advertiserRoleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $approved = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-OK-1',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $approved->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_PAYMENT_RECEIVED,
            'title' => 'Deposit approved — €50.00',
        ]);

        $rejected = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-NO-1',
            'amount' => 75,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $rejected->id), [
                'admin_notes' => 'Proof of transfer missing.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $note = InAppNotification::where('user_id', $advertiser->id)
            ->where('type', InAppNotificationService::TYPE_PAYMENT_FAILED)
            ->where('title', 'Deposit rejected — €75.00')
            ->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Proof of transfer missing', (string) $note->message);
    }

    public function test_withdrawal_paid_and_cancelled_bell_notifications(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $publisherRoleId = Wallet::publisherRoleId();
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisherRoleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $paid = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 100,
            'fee' => 5,
            'net_amount' => 95,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'processing',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.admin.withdrawals.update-status', $paid->id), [
                'status' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_PAYMENT_RECEIVED,
            'title' => 'Withdrawal paid — €95.00',
        ]);

        $cancelled = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 2,
            'net_amount' => 38,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.admin.withdrawals.update-status', $cancelled->id), [
                'status' => 'cancelled',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_PAYMENT_FAILED,
            'title' => 'Withdrawal cancelled — €40.00',
        ]);
        $this->assertEquals(40.0, (float) Wallet::where('user_id', $publisher->id)->where('role_id', $publisherRoleId)->first()->balance);
    }

    public function test_manual_approve_does_not_bell_advertiser(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeOrder($advertiser, $site, ['status' => 'review']);

        app(InAppNotificationService::class)->notifyOrderCompleted(
            $order,
            $publisher,
            100.0,
            false
        );

        $this->assertDatabaseMissing('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_ORDER_COMPLETED,
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_ORDER_COMPLETED,
        ]);
    }

    public function test_site_verify_and_activate_create_publisher_bells(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $site->update(['verified' => false, 'active' => false]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_SITE_STATUS,
        ]);
        $this->assertTrue(
            InAppNotification::where('user_id', $publisher->id)
                ->where('type', InAppNotificationService::TYPE_SITE_STATUS)
                ->where('title', 'like', 'Site verified%')
                ->exists()
        );

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk();

        $this->assertTrue(
            InAppNotification::where('user_id', $publisher->id)
                ->where('type', InAppNotificationService::TYPE_SITE_STATUS)
                ->where('title', 'like', 'Site activated%')
                ->exists()
        );
    }

    public function test_deposit_submitted_creates_advertiser_pending_bell(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $advertiser->forceFill([
            'billing_name' => 'Test Adv',
            'company_name' => 'Test Co',
            'address' => '1 Test Street',
        ])->save();

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'wise',
                'reference_code' => 'DEP001',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_PAYMENT_PENDING,
            'title' => 'Deposit submitted — €50.00',
        ]);
    }

    public function test_withdrawal_processing_creates_publisher_bell(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $publisherRoleId = Role::where('name', 'publisher')->value('id');
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisherRoleId,
            'balance' => 200,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
        ]);

        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 80,
            'fee' => 4,
            'net_amount' => 76,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.admin.withdrawals.update-status', $withdrawal->id), [
                'status' => 'processing',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_PAYMENT_PENDING,
            'title' => 'Withdrawal processing — €80.00',
        ]);
    }

    public function test_scheduled_publish_due_bells_publisher(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeOrder($advertiser, $site, [
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->subMinute(),
            'schedule_released_at' => null,
            'status' => 'pending',
        ]);

        app(InAppNotificationService::class)->notifyScheduledPublishDue($order, false);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_ORDER_UPDATED,
        ]);
        $note = InAppNotification::where('user_id', $publisher->id)
            ->where('type', InAppNotificationService::TYPE_ORDER_UPDATED)
            ->first();
        $this->assertStringContainsString('Publish today', $note->title);
    }

    public function test_payment_pending_bell_is_deduped_per_order(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $service = app(InAppNotificationService::class);
        $service->notifyPaymentPending($order);
        $service->notifyPaymentPending($order);

        $this->assertSame(
            1,
            InAppNotification::where('user_id', $advertiser->id)
                ->where('type', InAppNotificationService::TYPE_PAYMENT_PENDING)
                ->where('related_id', $order->id)
                ->count()
        );
    }

    public function test_content_evaluation_uses_dedicated_types(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $service = app(InAppNotificationService::class);

        $service->notifyContentEvaluation($advertiser, (object) ['id' => 99], [
            'approved' => true,
            'message' => 'Looks good.',
            'moderation_status' => 'approved',
        ]);
        $service->notifyContentEvaluation($advertiser, (object) ['id' => 100], [
            'approved' => false,
            'title' => 'Article needs changes',
            'message' => 'Fix links.',
            'moderation_status' => 'needs_improvement',
            'matched_terms' => ['casino'],
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_CONTENT_APPROVED,
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_CONTENT_NEEDS_CHANGES,
        ]);
    }
}
