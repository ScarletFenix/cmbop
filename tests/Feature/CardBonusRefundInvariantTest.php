<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use App\Services\OrderPaymentService;
use App\Services\StripeCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Card checkout with leftover welcome bonus must not turn promo into cash
 * on reject, and must spend the reserve on approve.
 */
class CardBonusRefundInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_rejecting_card_plus_bonus_restores_promo_and_credits_only_cash(): void
    {
        [$advertiser, $publisher, $wallet, $item] = $this->paidCardOrderWithReservedBonus(80, 20);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'The topic does not fit our editorial guidelines.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_approving_card_plus_bonus_spends_the_reserved_promo(): void
    {
        [$advertiser, $publisher, $wallet, $item] = $this->paidCardOrderWithReservedBonus(80, 20);
        $item->order->update(['status' => 'review']);
        $item->update([
            'live_url' => 'https://card-bonus.example/live',
            'live_url_submitted_at' => now()->subHour(),
            'accepted_at' => now()->subHours(2),
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $item->order_id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_approving_card_plus_bonus_clears_leftover_hold(): void
    {
        [$advertiser, $publisher, $wallet, $item] = $this->paidCardOrderWithReservedBonus(80, 20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $item->order->reference_code, 20);
        $item->order->update(['status' => 'review']);
        $item->update([
            'live_url' => 'https://card-bonus.example/live-clear-hold',
            'live_url_submitted_at' => now()->subHour(),
            'accepted_at' => now()->subHours(2),
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $item->order_id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEqualsWithDelta(
            0.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, $item->order->reference_code),
            0.01
        );
        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_two_card_orders_sharing_bonus_split_promo_then_cash(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, reservedBonus: 20);
        $site = $this->site($publisher);

        $first = $this->cardOrder($advertiser, $site, 50, 'REF-CARD-BONUS-SPLIT');
        $second = $this->cardOrder($advertiser, $site, 50, 'REF-CARD-BONUS-SPLIT');

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $first->id), [
                'reason' => 'First placement is not a fit for the site.',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(40.0, $wallet->withdrawableBalance(), 0.01);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $second->id), [
                'reason' => 'Second placement is also not a fit for the site.',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_marking_card_orders_paid_does_not_consume_reserved_bonus(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, reservedBonus: 20);
        $site = $this->site($publisher);
        $item = $this->cardOrder($advertiser, $site, 80, 'REF-KEEP-BONUS', 'pending');

        $session = (object) [
            'id' => 'cs_keep_bonus',
            'object' => 'checkout.session',
            'amount_total' => 6000,
            'payment_intent' => 'pi_keep_bonus',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-KEEP-BONUS',
                'expected_amount' => '60',
                'bonus_applied' => '20',
            ],
        ];

        app(OrderPaymentService::class)->markOrdersPaidFromStripeSession('REF-KEEP-BONUS', $session);

        $this->assertSame('paid', $item->order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_approving_one_sibling_then_rejecting_the_other_does_not_mint_promo(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, reservedBonus: 20);
        $site = $this->site($publisher);

        $first = $this->cardOrder($advertiser, $site, 50, 'REF-CARD-BONUS-APPROVE-SPLIT');
        $second = $this->cardOrder($advertiser, $site, 50, 'REF-CARD-BONUS-APPROVE-SPLIT');

        $first->order->update(['status' => 'review']);
        $first->update([
            'live_url' => 'https://card-bonus.example/live-a',
            'live_url_submitted_at' => now()->subHour(),
            'accepted_at' => now()->subHours(2),
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $first->order_id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, $wallet->withdrawableBalance(), 0.01);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $second->id), [
                'reason' => 'Second placement is not a fit after the first went live.',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(40.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_wallet_approve_then_reject_sibling_does_not_mint_promo(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 100,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);
        $site = $this->site($publisher);

        $first = $this->walletOrder($advertiser, $site, 50, 'REF-WALLET-BONUS-APPROVE-SPLIT');
        $second = $this->walletOrder($advertiser, $site, 50, 'REF-WALLET-BONUS-APPROVE-SPLIT');

        $first->order->update(['status' => 'review']);
        $first->update([
            'live_url' => 'https://card-bonus.example/live-wallet-a',
            'live_url_submitted_at' => now()->subHour(),
            'accepted_at' => now()->subHours(2),
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $first->order_id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, $wallet->withdrawableBalance(), 0.01);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $second->id), [
                'reason' => 'Second wallet placement is not a fit after the first went live.',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(40.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_wallet_approve_first_sibling_does_not_burn_other_leftover(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 120,
            'bonus_balance' => 0,
            'bonus_reserved' => 40,
            'currency' => 'EUR',
        ]);
        $site = $this->site($publisher);
        $first = $this->walletOrder($advertiser, $site, 50, 'REF-WALLET-APPROVE-SIB');
        $this->walletOrder($advertiser, $site, 50, 'REF-WALLET-APPROVE-SIB');
        $first->order->update(['status' => 'review']);
        $first->update([
            'live_url' => 'https://card-bonus.example/live-wallet-sib',
            'live_url_submitted_at' => now()->subHour(),
            'accepted_at' => now()->subHours(2),
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-APPROVE-SIB', 20);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $first->order_id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(70.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'REF-OTHER-APPROVE-SIB'),
            0.01
        );
    }

    public function test_card_approve_does_not_burn_another_checkout_leftover(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 40,
            'bonus_balance' => 0,
            'bonus_reserved' => 40,
            'currency' => 'EUR',
        ]);
        $site = $this->site($publisher);
        $item = $this->cardOrder($advertiser, $site, 80, 'REF-CARD-APPROVE-ISO');
        $item->order->update(['status' => 'review']);
        $item->update([
            'live_url' => 'https://card-bonus.example/live-card-iso',
            'live_url_submitted_at' => now()->subHour(),
            'accepted_at' => now()->subHours(2),
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-CARD-APPROVE-ISO', 20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-CARD-APPROVE', 20);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $item->order_id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'REF-OTHER-CARD-APPROVE'),
            0.01
        );
    }

    public function test_rejecting_card_does_not_steal_another_checkout_leftover(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 40,
            'bonus_balance' => 0,
            'bonus_reserved' => 40,
            'currency' => 'EUR',
        ]);
        $item = $this->cardOrder($advertiser, $this->site($publisher), 80, 'REF-CARD-REJECT-ISO');
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-CARD-REJECT-ISO', 20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-CARD-REJECT', 20);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'The topic does not fit our editorial guidelines.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'REF-OTHER-CARD-REJECT'),
            0.01
        );
    }

    public function test_wallet_approve_does_not_burn_another_checkout_leftover(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 135,
            'bonus_balance' => 0,
            'bonus_reserved' => 40,
            'currency' => 'EUR',
        ]);
        $item = $this->walletOrder($advertiser, $this->site($publisher), 115, 'REF-WALLET-APPROVE-ISO');
        $item->order->update(['status' => 'review']);
        $item->update([
            'live_url' => 'https://card-bonus.example/live-wallet-iso',
            'live_url_submitted_at' => now()->subHour(),
            'accepted_at' => now()->subHours(2),
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-LEFTOVER-APPROVE', 20);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $item->order_id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'REF-OTHER-LEFTOVER-APPROVE'),
            0.01
        );
    }

    public function test_admin_mark_paid_after_fail_rereserves_promo_so_reject_does_not_mint_cash(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, reservedBonus: 20);
        $site = $this->site($publisher);
        $item = $this->cardOrder($advertiser, $site, 80, 'REF-ADMIN-AFTER-FAIL', 'pending');
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-ADMIN-AFTER-FAIL', [
            'user_id' => $advertiser->id,
            'reference_code' => 'REF-ADMIN-AFTER-FAIL',
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [['site_id' => $site->id, 'price' => 80]],
            'stripe_session_id' => 'cs_will_expire',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-ADMIN-AFTER-FAIL', 20);

        $payments->markOrdersFailedFromReference('REF-ADMIN-AFTER-FAIL', 'expired');

        $wallet->refresh();
        $this->assertSame('failed', $item->order->fresh()->payment_status);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            0.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'REF-ADMIN-AFTER-FAIL'),
            0.01
        );
        $this->assertEqualsWithDelta(
            20.0,
            $payments->leftoverBonusForPurchaseLedger($item->order->fresh()),
            0.01
        );

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $item->order_id), [
                'payment_status' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertSame('paid', $item->order->fresh()->payment_status);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, $wallet->withdrawableBalance(), 0.01);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'The topic does not fit our editorial guidelines.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_pay_again_full_card_settle_does_not_keep_fail_bonus_snapshot(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, reservedBonus: 20);
        $site = $this->site($publisher);
        $item = $this->cardOrder($advertiser, $site, 80, 'REF-PAY-AGAIN-NO-SNAP', 'pending');
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-PAY-AGAIN-NO-SNAP', [
            'user_id' => $advertiser->id,
            'reference_code' => 'REF-PAY-AGAIN-NO-SNAP',
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [['site_id' => $site->id, 'price' => 80]],
            'stripe_session_id' => 'cs_will_expire_retry',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-PAY-AGAIN-NO-SNAP', 20);

        $payments->markOrdersFailedFromReference('REF-PAY-AGAIN-NO-SNAP', 'expired');
        $this->assertEqualsWithDelta(
            20.0,
            $payments->leftoverBonusForPurchaseLedger($item->order->fresh()),
            0.01
        );

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('createCheckoutSession')->once()->andReturn(
                Session::constructFrom([
                    'id' => 'cs_pay_again_full',
                    'object' => 'checkout.session',
                    'url' => 'https://checkout.stripe.test/pay-again',
                ])
            );
        });

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.retry-payment', $item->order_id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('amount_due', 80);

        $this->assertNull($payments->getPendingCheckout('REF-PAY-AGAIN-NO-SNAP'));

        $session = (object) [
            'id' => 'cs_pay_again_full',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_pay_again_full',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-PAY-AGAIN-NO-SNAP',
                'expected_amount' => '80',
                'order_total' => '80',
                'bonus_applied' => '0',
            ],
        ];

        $paid = $payments->markOrdersPaidFromStripeSession('REF-PAY-AGAIN-NO-SNAP', $session);
        $this->assertCount(1, $paid);
        $payments->forgetPendingCheckoutKeepLeftoverHold('REF-PAY-AGAIN-NO-SNAP', $advertiser->id);

        $this->assertNull($payments->getPendingCheckout('REF-PAY-AGAIN-NO-SNAP'));
        $this->assertEqualsWithDelta(
            0.0,
            $payments->leftoverBonusForPurchaseLedger($item->order->fresh()),
            0.01
        );

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'The topic does not fit our editorial guidelines.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_admin_mark_paid_then_reject_restores_promo_not_cash(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, reservedBonus: 20);
        $site = $this->site($publisher);
        $item = $this->cardOrder($advertiser, $site, 80, 'REF-ADMIN-KEEP-BONUS', 'pending');
        $item->order->update(['payment_method' => 'wise']);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $item->order_id), [
                'payment_status' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, $wallet->withdrawableBalance(), 0.01);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'Admin marked paid but the placement is not a fit.',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_admin_fail_pending_sibling_does_not_release_paid_siblings_bonus(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, reservedBonus: 20);
        $site = $this->site($publisher);

        $paid = $this->cardOrder($advertiser, $site, 50, 'REF-ADMIN-FAIL-SPLIT');
        $pending = $this->cardOrder($advertiser, $site, 50, 'REF-ADMIN-FAIL-SPLIT', 'pending');
        $pending->order->update(['payment_method' => 'wise']);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $pending->order_id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, $wallet->withdrawableBalance(), 0.01);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $paid->id), [
                'reason' => 'Paid sibling must restore only its leftover promo share.',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(60.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(40.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_wallet_reject_first_sibling_keeps_half_the_promo_reserved(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 100,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);
        $site = $this->site($publisher);

        $first = $this->walletOrder($advertiser, $site, 50, 'REF-WALLET-REJECT-FIRST');
        $this->walletOrder($advertiser, $site, 50, 'REF-WALLET-REJECT-FIRST');

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $first->id), [
                'reason' => 'First wallet placement is not a fit for the site.',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(40.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_refund_paid_then_fail_pending_sibling_does_not_steal_other_leftover(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 40,
            'bonus_balance' => 0,
            'bonus_reserved' => 40,
            'currency' => 'EUR',
        ]);
        $site = $this->site($publisher);

        $paid = $this->cardOrder($advertiser, $site, 50, 'REF-REFUND-THEN-FAIL');
        $pending = $this->cardOrder($advertiser, $site, 50, 'REF-REFUND-THEN-FAIL', 'pending');
        $pending->order->update(['payment_method' => 'wise']);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-REFUND-THEN-FAIL', 20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'REF-OTHER-LEFTOVER', 20);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $paid->order_id), [
                'payment_status' => 'refunded',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            10.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'REF-REFUND-THEN-FAIL'),
            0.01
        );

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $pending->order_id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(60.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'REF-OTHER-LEFTOVER'),
            0.01
        );
    }

    public function test_admin_fail_one_wallet_sibling_keeps_half_the_promo_reserved(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 100,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);
        $site = $this->site($publisher);

        $first = $this->walletOrder($advertiser, $site, 50, 'REF-WALLET-ADMIN-FAIL-SPLIT');
        $this->walletOrder($advertiser, $site, 50, 'REF-WALLET-ADMIN-FAIL-SPLIT');

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $first->order_id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(40.0, $wallet->withdrawableBalance(), 0.01);
    }

    /**
     * @return array{0: User, 1: User, 2: Wallet, 3: OrderItem}
     */
    private function paidCardOrderWithReservedBonus(float $orderTotal, float $bonus): array
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $wallet = $this->wallet($advertiser, reservedBonus: $bonus);
        $site = $this->site($publisher);
        $item = $this->cardOrder($advertiser, $site, $orderTotal);

        return [$advertiser, $publisher, $wallet, $item];
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
            'site_name' => 'Card Bonus Site',
            'site_url' => 'https://card-bonus.example',
            'domain' => 'card-bonus.example',
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
            'description' => 'Card plus bonus refund fixture',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function cardOrder(
        User $advertiser,
        Site $site,
        float $amount,
        string $reference = 'REF-CARD-BONUS',
        string $paymentStatus = 'paid'
    ): OrderItem {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CB-'.uniqid(),
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

    private function walletOrder(
        User $advertiser,
        Site $site,
        float $amount,
        string $reference
    ): OrderItem {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-WB-'.uniqid(),
            'reference_code' => $reference,
            'subtotal' => $amount,
            'tax' => 0,
            'total_amount' => $amount,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
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
