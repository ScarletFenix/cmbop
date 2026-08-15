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

    public function test_fail_pending_sibling_keeps_paid_sibling_leftover_hold(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher);
        $wallet = $this->wallet($advertiser, 40);
        $this->paidCardOrder($advertiser, $site, 80, 'REF-PAID-SIB-FAIL');
        $this->cardOrder($advertiser, $site, 50, 'REF-PAID-SIB-FAIL', 'pending');
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-PAID-SIB-FAIL', 20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-FAIL-HOLD', 20);

        app(OrderPaymentService::class)->markOrdersFailedFromReference('REF-PAID-SIB-FAIL', 'expired');

        $leftover = round(20 - (20 * (50 / 130)), 2);
        $this->assertEqualsWithDelta(
            $leftover,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-PAID-SIB-FAIL'),
            0.01
        );
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-OTHER-FAIL-HOLD'),
            0.01
        );
        $wallet->refresh();
        $this->assertEqualsWithDelta(round(40 - (20 * (50 / 130)), 2), (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(round(20 * (50 / 130), 2), (float) $wallet->bonus_balance, 0.01);
    }

    public function test_cancel_url_keeps_paid_sibling_leftover_hold(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher);
        $wallet = $this->wallet($advertiser, 40);
        $this->paidCardOrder($advertiser, $site, 80, 'REF-PAID-SIB-CANCEL');
        $this->cardOrder($advertiser, $site, 50, 'REF-PAID-SIB-CANCEL', 'pending');
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-PAID-SIB-CANCEL', 20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-CANCEL-HOLD', 20);

        $this->actingAs($advertiser)
            ->withSession(['cart' => [['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1]]])
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'REF-PAID-SIB-CANCEL']));

        $leftover = round(20 - (20 * (50 / 130)), 2);
        $this->assertEqualsWithDelta(
            $leftover,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-PAID-SIB-CANCEL'),
            0.01
        );
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-OTHER-CANCEL-HOLD'),
            0.01
        );
        $wallet->refresh();
        $this->assertEqualsWithDelta(round(40 - (20 * (50 / 130)), 2), (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_reject_after_fail_sibling_does_not_mint_cash_when_other_checkout_is_open(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher);
        $wallet = $this->wallet($advertiser, 40);
        $paid = $this->paidCardOrder($advertiser, $site, 80, 'REF-PAID-SIB-REJECT');
        $this->cardOrder($advertiser, $site, 50, 'REF-PAID-SIB-REJECT', 'pending');
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-PAID-SIB-REJECT', 20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-REJECT-HOLD', 20);

        app(OrderPaymentService::class)->markOrdersFailedFromReference('REF-PAID-SIB-REJECT', 'expired');

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $paid->id), [
                'reason' => 'The topic does not fit our editorial guidelines.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $leftover = round(20 - (20 * (50 / 130)), 2);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(round(80 - $leftover, 2), $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-OTHER-REJECT-HOLD'),
            0.01
        );
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

    public function test_finalize_snapshot_only_package_marks_failed_leftover_instead_of_wallet_credit(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, 20);
        $item = $this->cardOrder($advertiser, $this->site($publisher), 80, 'REF-SNAP-ONLY', 'pending');
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-SNAP-ONLY', [
            'user_id' => $advertiser->id,
            'reference_code' => 'REF-SNAP-ONLY',
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [['site_id' => $item->site_id, 'price' => 80]],
            'stripe_session_id' => 'cs_will_expire_snap',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-SNAP-ONLY', 20);

        $payments->markOrdersFailedFromReference('REF-SNAP-ONLY', 'expired');

        $wallet->refresh();
        $this->assertSame('failed', $item->order->fresh()->payment_status);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertSame([], $payments->getPendingCheckout('REF-SNAP-ONLY')['lines'] ?? null);

        $session = (object) [
            'id' => 'cs_pay_again_full',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_pay_again_full',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-SNAP-ONLY',
                'expected_amount' => '80',
                'order_total' => '80',
                'bonus_applied' => '0',
            ],
        ];

        $paid = $payments->finalizeStripeFirstCheckout('REF-SNAP-ONLY', $session);

        $this->assertSame(1, $paid->count());
        $this->assertSame('paid', $item->order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
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
            0.0,
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

    public function test_late_mark_paid_credits_card_when_released_bonus_was_spent(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, 20);
        $item = $this->cardOrder($advertiser, $this->site($publisher), 80, 'REF-BONUS-SPENT', 'pending');
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-BONUS-SPENT', 20);

        app(OrderPaymentService::class)->markOrdersFailedFromReference('REF-BONUS-SPENT', 'expired');

        $wallet->refresh();
        $this->assertSame('failed', $item->order->fresh()->payment_status);
        $this->assertEqualsWithDelta(20.0, $wallet->reserveBonusOnly(20), 0.01);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-CART', 20);

        $session = (object) [
            'id' => 'cs_bonus_spent',
            'object' => 'checkout.session',
            'amount_total' => 6000,
            'payment_intent' => 'pi_bonus_spent',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-BONUS-SPENT',
                'expected_amount' => '60',
                'bonus_applied' => '20',
            ],
        ];

        $paid = app(OrderPaymentService::class)->markOrdersPaidFromStripeSession('REF-BONUS-SPENT', $session);

        $this->assertTrue($paid->isEmpty());
        $this->assertSame('failed', $item->order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-OTHER-CART'),
            0.01
        );
        $this->assertEqualsWithDelta(
            0.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-BONUS-SPENT'),
            0.01
        );
        $this->assertEqualsWithDelta(
            60.0,
            app(OrderPaymentService::class)->unfulfilledCardCreditAmount('REF-BONUS-SPENT'),
            0.01
        );
    }

    public function test_leftover_first_finalize_credits_when_package_bonus_was_spent(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, 20);
        $item = $this->cardOrder($advertiser, $this->site($publisher), 80, 'REF-LEFTOVER-BONUS-SPENT', 'pending');
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-LEFTOVER-BONUS-SPENT', [
            'user_id' => $advertiser->id,
            'reference_code' => 'REF-LEFTOVER-BONUS-SPENT',
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'stripe_session_id' => 'cs_leftover_bonus_spent',
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [],
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-LEFTOVER-BONUS-SPENT', 20);

        $payments->markOrdersFailedFromReference('REF-LEFTOVER-BONUS-SPENT', 'expired');
        $payments->storePendingCheckout('REF-LEFTOVER-BONUS-SPENT', [
            'user_id' => $advertiser->id,
            'reference_code' => 'REF-LEFTOVER-BONUS-SPENT',
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'stripe_session_id' => 'cs_leftover_bonus_spent',
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [],
        ]);
        // fail() deletes the intent. Re-storing the late-pay snapshot must
        // not recreate a live hold — package JSON is not reserved promo.
        app(CheckoutIntentService::class)->forgetBonus($advertiser->id, 'REF-LEFTOVER-BONUS-SPENT');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, $wallet->reserveBonusOnly(20), 0.01);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-PACKAGE', 20);

        $session = (object) [
            'id' => 'cs_leftover_bonus_spent',
            'object' => 'checkout.session',
            'amount_total' => 6000,
            'payment_intent' => 'pi_leftover_bonus_spent',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-LEFTOVER-BONUS-SPENT',
                'expected_amount' => '60',
                'bonus_applied' => '20',
                'user_id' => (string) $advertiser->id,
            ],
        ];

        $created = $payments->finalizeStripeFirstCheckout('REF-LEFTOVER-BONUS-SPENT', $session);

        $this->assertTrue($created->isEmpty());
        $this->assertSame('failed', $item->order->fresh()->payment_status);
        $this->assertSame(1, Order::query()->where('reference_code', 'REF-LEFTOVER-BONUS-SPENT')->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-OTHER-PACKAGE'),
            0.01
        );
        $this->assertEqualsWithDelta(60.0, $payments->unfulfilledCardCreditAmount('REF-LEFTOVER-BONUS-SPENT'), 0.01);
    }

    public function test_second_cancel_does_not_steal_another_checkouts_reserved_bonus(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->wallet($advertiser, 20);
        $payments = app(OrderPaymentService::class);
        $intents = app(CheckoutIntentService::class);
        $refunds = app(OrderRefundService::class);

        $payments->storePendingCheckout('REF-FIRST-CANCEL', [
            'user_id' => $advertiser->id,
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [],
        ]);
        $intents->rememberBonus($advertiser->id, 'REF-FIRST-CANCEL', 20);

        $refunds->releaseReservedCheckoutBonusForReference(
            $advertiser->id,
            'REF-FIRST-CANCEL',
            collect(),
            20
        );

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);

        $wallet->reserveBonusOnly(20);
        $intents->rememberBonus($advertiser->id, 'REF-SECOND-CART', 20);

        $package = $payments->getPendingCheckout('REF-FIRST-CANCEL');
        $fallback = is_array($package) ? round((float) ($package['bonus_applied'] ?? 0), 2) : 0.0;
        $refunds->releaseReservedCheckoutBonusForReference(
            $advertiser->id,
            'REF-FIRST-CANCEL',
            collect(),
            $fallback > 0 ? $fallback : 20
        );

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, $intents->heldBonus($advertiser->id, 'REF-SECOND-CART'), 0.01);
        $this->assertEqualsWithDelta(0.0, $intents->heldBonus($advertiser->id, 'REF-FIRST-CANCEL'), 0.01);
    }

    public function test_restoring_leftover_package_after_cancel_does_not_steal_other_hold(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->wallet($advertiser, 20);
        $payments = app(OrderPaymentService::class);
        $intents = app(CheckoutIntentService::class);
        $refunds = app(OrderRefundService::class);

        $payments->storePendingCheckout('REF-FIRST-CANCEL', [
            'user_id' => $advertiser->id,
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [],
        ]);
        $intents->rememberBonus($advertiser->id, 'REF-FIRST-CANCEL', 20);

        $refunds->releaseReservedCheckoutBonusForReference(
            $advertiser->id,
            'REF-FIRST-CANCEL',
            collect(),
            20
        );

        $leftover = $payments->getPendingCheckout('REF-FIRST-CANCEL');
        $this->assertIsArray($leftover);
        $leftover['stripe_session_id'] = 'cs_written_after_cancel';
        $payments->storePendingCheckout('REF-FIRST-CANCEL', $leftover);

        $this->assertEqualsWithDelta(0.0, $intents->heldBonus($advertiser->id, 'REF-FIRST-CANCEL'), 0.01);

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, $wallet->reserveBonusOnly(20), 0.01);
        $intents->rememberBonus($advertiser->id, 'REF-SECOND-CART', 20);

        $fallback = round((float) ($leftover['bonus_applied'] ?? 0), 2);
        $refunds->releaseReservedCheckoutBonusForReference(
            $advertiser->id,
            'REF-FIRST-CANCEL',
            collect(),
            $fallback > 0 ? $fallback : 20
        );

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, $intents->heldBonus($advertiser->id, 'REF-SECOND-CART'), 0.01);
        $this->assertEqualsWithDelta(0.0, $intents->heldBonus($advertiser->id, 'REF-FIRST-CANCEL'), 0.01);
        $this->assertSame(
            'cs_written_after_cancel',
            $payments->getPendingCheckout('REF-FIRST-CANCEL')['stripe_session_id'] ?? null
        );
    }

    public function test_stale_package_json_does_not_burn_other_checkout_on_approve(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, 20);
        $order = $this->paidCardOrder($advertiser, $this->site($publisher), 80, 'REF-STALE-APPROVE')->order;
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-HOLD', 20);
        CheckoutIntent::query()->create([
            'user_id' => $advertiser->id,
            'reference_code' => 'REF-STALE-APPROVE',
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

        app(OrderRefundService::class)->consumeReservedForSettledOrder($order->fresh(), $wallet->fresh());

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-OTHER-HOLD'),
            0.01
        );
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
