<?php

namespace Tests\Feature;

use App\Models\CheckoutIntent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use App\Services\OrderPaymentService;
use App\Services\Orders\OrderRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class CheckoutCancelBonusGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Mockery::close();
        parent::tearDown();
    }

    public function test_cancel_of_unknown_ref_does_not_dump_another_checkouts_bonus(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, 20);
        $this->paidCardOrder($advertiser, $this->site($publisher), 80, 'REF-PAID-BONUS');

        $this->actingAs($advertiser)
            ->withSession(['cart' => [['id' => 1, 'name' => 'Keep cart', 'quantity' => 1]]])
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'REF-NOT-THIS']))
            ->assertSuccessful();

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_empty_failed_release_caps_to_this_reference_bonus(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->wallet($advertiser, 40);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-ABANDON', 20);

        $released = app(OrderRefundService::class)->releaseReservedCheckoutBonusForReference(
            $advertiser->id,
            'REF-ABANDON',
            collect(),
            20
        );

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, $released, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_cancel_skips_bonus_release_when_stripe_session_already_paid(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->wallet($advertiser, 20);
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-PAID-SESSION', [
            'user_id' => $advertiser->id,
            'reference_code' => 'REF-PAID-SESSION',
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'stripe_session_id' => 'cs_test_already_paid',
            'lines' => [],
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-PAID-SESSION', 20);

        $this->fakePaidStripeSession('cs_test_already_paid');

        $response = $this->actingAs($advertiser)
            ->withSession(['cart' => [['id' => 1, 'name' => 'Keep cart', 'quantity' => 1]]])
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'REF-PAID-SESSION']));
        $this->assertNotEquals(500, $response->status());

        $this->assertNotNull($payments->getPendingCheckout('REF-PAID-SESSION'));
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_cancel_of_failed_leftover_does_not_dump_paid_orders_bonus(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher);
        $wallet = $this->wallet($advertiser, 20);
        $this->paidCardOrder($advertiser, $site, 80, 'REF-PAID-BONUS');
        $this->cardOrder($advertiser, $site, 50, 'REF-OLD-FAILED', 'failed');

        $this->actingAs($advertiser)
            ->withSession(['cart' => [['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1]]])
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'REF-OLD-FAILED']));

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_late_mark_paid_rereserves_bonus_released_on_fail(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, 20);
        $item = $this->cardOrder($advertiser, $this->site($publisher), 80, 'REF-LATE-PAID', 'pending');
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-LATE-PAID', 20);

        app(OrderPaymentService::class)->markOrdersFailedFromReference('REF-LATE-PAID', 'expired');

        $wallet->refresh();
        $this->assertSame('failed', $item->order->fresh()->payment_status);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);

        $session = (object) [
            'id' => 'cs_late_paid',
            'object' => 'checkout.session',
            'amount_total' => 6000,
            'payment_intent' => 'pi_late_paid',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-LATE-PAID',
                'expected_amount' => '60',
                'bonus_applied' => '20',
            ],
        ];

        app(OrderPaymentService::class)->markOrdersPaidFromStripeSession('REF-LATE-PAID', $session);

        $this->assertSame('paid', $item->order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_late_mark_paid_rereserves_bonus_when_package_json_still_lists_it(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, 20);
        $item = $this->cardOrder($advertiser, $this->site($publisher), 80, 'REF-STALE-PKG-BONUS', 'pending');
        $payments = app(OrderPaymentService::class);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-STALE-PKG-BONUS', 20);

        $payments->markOrdersFailedFromReference('REF-STALE-PKG-BONUS', 'expired');
        CheckoutIntent::query()->create([
            'user_id' => $advertiser->id,
            'reference_code' => 'REF-STALE-PKG-BONUS',
            'bonus_applied' => 0,
            'package' => [
                'user_id' => $advertiser->id,
                'order_total' => 80,
                'amount_due' => 60,
                'bonus_applied' => 20,
                'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
                'lines' => [],
            ],
            'expires_at' => now()->addDay(),
        ]);

        $wallet->refresh();
        $this->assertSame('failed', $item->order->fresh()->payment_status);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(
            0.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-STALE-PKG-BONUS'),
            0.01
        );
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'REF-STALE-PKG-BONUS'),
            0.01
        );

        $session = (object) [
            'id' => 'cs_stale_pkg_bonus',
            'object' => 'checkout.session',
            'amount_total' => 6000,
            'payment_intent' => 'pi_stale_pkg_bonus',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-STALE-PKG-BONUS',
                'expected_amount' => '60',
                'bonus_applied' => '20',
            ],
        ];

        $payments->markOrdersPaidFromStripeSession('REF-STALE-PKG-BONUS', $session);

        $this->assertSame('paid', $item->order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);

        app(OrderRefundService::class)->cancelAndRefund($item->order->fresh());
        $wallet->refresh();
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(20.0, $wallet->lockedBonusBalance(), 0.01);
    }

    private function fakePaidStripeSession(string $sessionId): void
    {
        config([
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $sessionBody = json_encode([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'status' => 'complete',
            'mode' => 'payment',
            'metadata' => [],
        ], JSON_THROW_ON_ERROR);

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->andReturn([$sessionBody, 200, []]);
        ApiRequestor::setHttpClient($client);
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function wallet(User $advertiser, float $reservedBonus): Wallet
    {
        return Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => $reservedBonus,
            'bonus_balance' => 0,
            'bonus_reserved' => $reservedBonus,
            'currency' => 'EUR',
        ]);
    }

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Cancel Guard Site',
            'site_url' => 'https://cancel-guard.example',
            'domain' => 'cancel-guard.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Cancel bonus guard fixture',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function paidCardOrder(User $advertiser, Site $site, float $amount, string $reference): OrderItem
    {
        return $this->cardOrder($advertiser, $site, $amount, $reference, 'paid');
    }

    private function cardOrder(
        User $advertiser,
        Site $site,
        float $amount,
        string $reference,
        string $paymentStatus = 'paid'
    ): OrderItem {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CG-'.uniqid(),
            'reference_code' => $reference,
            'subtotal' => $amount,
            'tax' => 0,
            'total_amount' => $amount,
            'payment_method' => 'card',
            'payment_status' => $paymentStatus,
            'status' => 'pending',
            'paid_at' => $paymentStatus === 'paid' ? now() : null,
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => $amount,
            'publisher_price' => 70,
        ]);
    }
}
