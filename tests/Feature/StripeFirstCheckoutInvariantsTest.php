<?php

namespace Tests\Feature;

use App\Models\CheckoutIntent;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use App\Services\OrderPaymentService;
use App\Services\Orders\OrderRefundService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class StripeFirstCheckoutInvariantsTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_stripe_first_invariants';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
        config(['services.stripe.webhook_secret' => $this->webhookSecret]);
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeSite(User $publisher, string $domain, float $price = 40): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Stripe '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => $price,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Stripe first checkout site. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function advertiserWallet(User $advertiser, float $bonus): Wallet
    {
        return Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => $bonus,
            'reserved_balance' => 0,
            'bonus_balance' => $bonus,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function package(User $advertiser, array $lines, float $amountDue, float $bonus = 0): array
    {
        return [
            'user_id' => $advertiser->id,
            'order_total' => $amountDue + $bonus,
            'amount_due' => $amountDue,
            'bonus_applied' => $bonus,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => $lines,
        ];
    }

    private function lineFor(Site $site, float $price): array
    {
        return [
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => $price,
            'sensitive_type' => null,
            'additional_price' => 0,
            'content_submission_id' => null,
            'content_link' => 'https://example.com/article',
            'anchor_text' => 'Example',
            'target_url' => 'https://example.com',
        ];
    }

    private function paidSession(string $ref, float $euros, string $sessionId = 'cs_test_finalize'): object
    {
        return (object) [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'amount_total' => (int) round($euros * 100),
            'payment_intent' => 'pi_'.substr($sessionId, -8),
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'expected_amount' => (string) $euros,
            ],
        ];
    }

    private function signedWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't='.$timestamp.',v1='.$signature,
            ],
            $payload
        );
    }

    public function test_finalize_is_idempotent_and_does_not_duplicate_site_rows(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $first = $this->makeSite($publisher, 'race-one.example', 40);
        $second = $this->makeSite($publisher, 'race-two.example', 60);
        $ref = 'RACE-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($first, 40),
            $this->lineFor($second, 60),
        ], 100));

        $session = $this->paidSession($ref, 100, 'cs_race_1');
        $firstPass = $payments->finalizeStripeFirstCheckout($ref, $session);
        $secondPass = $payments->finalizeStripeFirstCheckout($ref, $session);

        $this->assertCount(2, $firstPass);
        $this->assertSame(2, Order::where('reference_code', $ref)->count());
        $this->assertSame(2, OrderItem::whereIn('order_id', Order::where('reference_code', $ref)->pluck('id'))->count());
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            OrderItem::whereIn('order_id', Order::where('reference_code', $ref)->pluck('id'))
                ->pluck('site_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );
        $this->assertTrue($secondPass->every(fn (Order $order) => $order->payment_status === 'paid'));
    }

    public function test_stale_stripe_session_credits_wallet_instead_of_materializing_new_cart(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $this->advertiserWallet($advertiser, 0);
        $publisher = $this->makeUser('publisher');
        $currentSite = $this->makeSite($publisher, 'stale-current.example', 80);
        $ref = 'STALE-SESSION-1';
        $payments = app(OrderPaymentService::class);
        $package = $this->package($advertiser, [$this->lineFor($currentSite, 80)], 80);
        $package['stripe_session_id'] = 'cs_test_current_package';
        $payments->storePendingCheckout($ref, $package);

        $stale = $this->paidSession($ref, 40, 'cs_test_stale_session');
        $stale->metadata->user_id = (string) $advertiser->id;

        $created = $payments->finalizeStripeFirstCheckout($ref, $stale);

        $this->assertTrue($created->isEmpty());
        $this->assertSame(0, Order::where('reference_code', $ref)->count());
        $this->assertEqualsWithDelta(40.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
        $this->assertNotNull($payments->getPendingCheckout($ref));
    }

    public function test_payment_intent_amount_mismatch_credits_card_and_leaves_order_unpaid(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 0);
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'pi-mismatch.example');
        $ref = 'PI-MISMATCH-1';
        $payments = app(OrderPaymentService::class);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 115,
        ]);

        $intent = (object) [
            'id' => 'pi_wrong_amount',
            'object' => 'payment_intent',
            'amount' => 100,
            'amount_received' => 100,
            'metadata' => [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'expected_amount' => '115',
            ],
        ];

        $paid = $payments->markOrdersPaidFromPaymentIntent($ref, $intent);

        $this->assertCount(0, $paid);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(1.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(1.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_checkout_session_with_bonus_metadata_credits_mismatch_and_leaves_order_unpaid(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 0);
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'session-bonus-mismatch.example');
        $ref = 'CS-BONUS-MISMATCH-1';
        $payments = app(OrderPaymentService::class);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 115,
        ]);

        $session = (object) [
            'id' => 'cs_bonus_mismatch',
            'object' => 'checkout.session',
            'amount_total' => 100,
            'payment_intent' => 'pi_bonus_mismatch',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'bonus_applied' => '20',
            ],
        ];

        $paid = $payments->markOrdersPaidFromStripeSession($ref, $session);

        $this->assertCount(0, $paid);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(1.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(1.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_stale_pending_order_session_credits_card_and_matching_pay_still_marks_paid(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 0);
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'stale-pending-order.example', 80);
        $ref = 'STALE-PENDING-1';
        $payments = app(OrderPaymentService::class);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 80,
        ]);

        $stale = $payments->markOrdersPaidFromStripeSession(
            $ref,
            $this->paidSession($ref, 50, 'cs_stale_pending')
        );
        $this->assertCount(0, $stale);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);

        $paid = $payments->markOrdersPaidFromStripeSession(
            $ref,
            $this->paidSession($ref, 80, 'cs_matching_pending')
        );
        $this->assertCount(1, $paid);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(50.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_webhook_credits_stale_pending_order_capture_without_500(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 0);
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'wh-stale-pending.example', 80);
        $ref = 'WH-STALE-PENDING-1';

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 80,
        ]);

        $this->signedWebhook([
            'id' => 'evt_wh_stale_pending_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_wh_stale_pending',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 5000,
                    'payment_intent' => 'pi_wh_stale_pending',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame('failed', $order->fresh()->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(50.0, app(OrderPaymentService::class)->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_session_expiry_refunds_bonus_when_no_order_rows_exist(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'expire-bonus.example');
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'EXPIRE-BONUS-1';

        Cache::put('checkout_bonus:'.$advertiser->id.':'.$ref, 20, now()->addHour());
        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package(
            $advertiser,
            [$this->lineFor($site, 40)],
            20,
            20
        ));

        $this->signedWebhook([
            'id' => 'evt_expire_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_expired_bonus',
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(0, Order::where('reference_code', $ref)->count());
        $this->assertNotNull(app(OrderPaymentService::class)->getPendingCheckout($ref));
        $this->assertNull(Cache::get('checkout_bonus:'.$advertiser->id.':'.$ref));

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_payment_intent_webhook_materializes_stripe_first_package(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'pi-package.example');
        $ref = 'PI-PKG-1';

        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package(
            $advertiser,
            [$this->lineFor($site, 80)],
            80
        ));

        $this->signedWebhook([
            'id' => 'evt_pi_pkg_'.uniqid(),
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_pkg_materialize',
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 8000,
                    'amount_received' => 8000,
                    'currency' => 'eur',
                    'metadata' => [
                        'type' => 'order_payment',
                        'user_id' => (string) $advertiser->id,
                        'reference_code' => $ref,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $order = Order::where('reference_code', $ref)->where('payment_method', 'card')->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertEqualsWithDelta(80.0, (float) $order->total_amount, 0.01);
        $this->assertSame($site->id, (int) $order->items()->first()?->site_id);
    }

    public function test_finalize_survives_cache_flush_via_durable_checkout_intent(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'durable-package.example', 80);
        $ref = 'DURABLE-PKG-1';

        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package(
            $advertiser,
            [$this->lineFor($site, 80)],
            80
        ));

        Cache::flush();
        $this->assertNull(Cache::get(OrderPaymentService::pendingCheckoutCacheKey($ref)));

        $created = app(OrderPaymentService::class)->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 80, 'cs_durable_pkg')
        );

        $this->assertCount(1, $created);
        $order = Order::where('reference_code', $ref)->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($site->id, (int) $order->items()->first()?->site_id);
    }

    public function test_session_expiry_refunds_bonus_after_cache_flush(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $this->makeSite($publisher, 'expire-durable.example');
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'EXPIRE-DURABLE-1';

        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package(
            $advertiser,
            [$this->lineFor($this->makeSite($publisher, 'expire-durable-line.example'), 40)],
            20,
            20
        ));

        Cache::flush();

        $this->signedWebhook([
            'id' => 'evt_expire_durable_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_expired_durable',
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'bonus_applied' => '20',
                    ],
                ],
            ],
        ])->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertNotNull(app(OrderPaymentService::class)->getPendingCheckout($ref));
    }

    public function test_session_expiry_late_payment_re_reserves_bonus(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'expire-late-pay.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'EXPIRE-LATE-PAY-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 60, 20));

        $this->signedWebhook([
            'id' => 'evt_expire_late_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_expire_late',
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'bonus_applied' => '20',
                    ],
                ],
            ],
        ])->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertNotNull($payments->getPendingCheckout($ref));

        $this->signedWebhook([
            'id' => 'evt_expire_late_paid_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_expire_late_paid',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 6000,
                    'payment_intent' => 'pi_expire_late_paid',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '60',
                        'bonus_applied' => '20',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, Order::query()->where('reference_code', $ref)->where('payment_status', 'paid')->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_finalize_skips_listing_that_left_the_catalog(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $hidden = $this->makeSite($publisher, 'left-catalog.example', 80);
        $live = $this->makeSite($publisher, 'still-live.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'LEFT-CATALOG-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($hidden, 80),
            $this->lineFor($live, 40),
        ], 120));

        $hidden->update(['verified' => false, 'active' => false]);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 120, 'cs_left_catalog')
        );

        $this->assertCount(1, $created);
        $this->assertSame($live->id, (int) $created->first()->items()->first()?->site_id);
        $this->assertSame(0, OrderItem::query()->where('site_id', $hidden->id)->count());

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_finalize_releases_bonus_share_for_listing_that_left_the_catalog(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $hidden = $this->makeSite($publisher, 'bonus-left-catalog.example', 80);
        $live = $this->makeSite($publisher, 'bonus-still-live.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'BONUS-LEFT-CATALOG-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($hidden, 80),
            $this->lineFor($live, 40),
        ], 100, 20));

        $hidden->update(['verified' => false, 'active' => false]);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 100, 'cs_bonus_left_catalog')
        );

        $this->assertCount(1, $created);
        $this->assertEqualsWithDelta(40.0, (float) $created->first()->total_amount, 0.01);
        $wallet->refresh();
        $this->assertEqualsWithDelta(60.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
        $this->assertEqualsWithDelta(round(20 * (40 / 120), 2), (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(round(20 - (20 * (40 / 120)), 2), (float) $wallet->bonus_balance, 0.01);
    }

    public function test_taken_library_line_plus_fulfilled_sibling_does_not_steal_other_hold(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $takenSite = $this->makeSite($publisher, 'taken-fulfill-steal.example', 80);
        $live = $this->makeSite($publisher, 'taken-fulfill-keep.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 40);
        $wallet->reserveBonusOnly(20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'OTHER-HOLD-1', 20);
        $wallet->reserveBonusOnly(20);

        $prior = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PRIOR-TAKEN-FULFILL-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $priorItem = OrderItem::create([
            'order_id' => $prior->id,
            'site_id' => $takenSite->id,
            'site_name' => $takenSite->site_name,
            'site_url' => $takenSite->site_url,
            'content_link' => 'https://example.com/prior-fulfill',
            'price' => 80,
        ]);
        $submission = $this->createApprovedSubmission($advertiser, $takenSite->id);
        $submission->update([
            'order_id' => $prior->id,
            'order_item_id' => $priorItem->id,
        ]);

        $ref = 'TAKEN-FULFILL-1';
        $payments = app(OrderPaymentService::class);
        $takenLine = $this->lineFor($takenSite, 80);
        $takenLine['content_submission_id'] = $submission->id;
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $takenLine,
            $this->lineFor($live, 80),
        ], 140, 20));
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $ref, 20);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 140, 'cs_taken_fulfill')
        );

        $this->assertCount(1, $created);
        $this->assertEqualsWithDelta(80.0, (float) $created->first()->total_amount, 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'OTHER-HOLD-1'),
            0.01
        );

        $wallet->refresh();
        $this->assertEqualsWithDelta(30.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_taken_library_line_plus_fulfilled_sibling_keeps_fulfilled_bonus_slice(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $takenSite = $this->makeSite($publisher, 'taken-fulfill-keep-bonus.example', 80);
        $live = $this->makeSite($publisher, 'taken-fulfill-live-bonus.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);

        $prior = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PRIOR-TAKEN-KEEP-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $priorItem = OrderItem::create([
            'order_id' => $prior->id,
            'site_id' => $takenSite->id,
            'site_name' => $takenSite->site_name,
            'site_url' => $takenSite->site_url,
            'content_link' => 'https://example.com/prior-keep',
            'price' => 80,
        ]);
        $submission = $this->createApprovedSubmission($advertiser, $takenSite->id);
        $submission->update([
            'order_id' => $prior->id,
            'order_item_id' => $priorItem->id,
        ]);

        $ref = 'TAKEN-FULFILL-KEEP-1';
        $payments = app(OrderPaymentService::class);
        $takenLine = $this->lineFor($takenSite, 80);
        $takenLine['content_submission_id'] = $submission->id;
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $takenLine,
            $this->lineFor($live, 80),
        ], 140, 20));
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $ref, 20);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 140, 'cs_taken_fulfill_keep')
        );

        $this->assertCount(1, $created);
        $wallet->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_finalize_creates_no_orders_when_every_line_left_the_catalog(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $hidden = $this->makeSite($publisher, 'all-left-catalog.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'ALL-LEFT-CATALOG-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($hidden, 80),
        ], 60, 20));

        $hidden->update(['verified' => false, 'active' => false]);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 60, 'cs_all_left_catalog')
        );

        $this->assertCount(0, $created);
        $this->assertSame(0, Order::query()->where('reference_code', $ref)->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(60.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_mark_paid_skips_legacy_order_when_listing_left_the_catalog(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'legacy-hidden.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'LEGACY-HIDDEN-1';

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 80,
        ]);

        $site->update(['verified' => false, 'active' => false]);

        $session = $this->paidSession($ref, 80, 'cs_legacy_hidden');
        $session->metadata->bonus_applied = '20';
        $session->metadata->order_total = '100';

        $paid = app(OrderPaymentService::class)->markOrdersPaidFromStripeSession($ref, $session);

        $this->assertCount(0, $paid);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('cancelled', $order->fresh()->status);

        $wallet->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            80.0,
            app(OrderPaymentService::class)->unfulfilledCardCreditAmount($ref),
            0.01
        );
    }

    public function test_hidden_catalog_cancel_releases_the_library_article(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'legacy-hidden-lib.example', 80);
        $this->advertiserWallet($advertiser, 0);
        $submission = $this->createApprovedSubmission($advertiser);
        $ref = 'LEGACY-HIDDEN-LIB-1';

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_submission_id' => $submission->id,
            'content_path' => $submission->path,
            'content_original_name' => $submission->original_filename,
            'content_link' => 'https://example.com/a',
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $order->items()->first()->id,
        ]);

        $site->update(['verified' => false, 'active' => false]);

        $paid = app(OrderPaymentService::class)->markOrdersPaidFromStripeSession(
            $ref,
            $this->paidSession($ref, 80, 'cs_legacy_hidden_lib')
        );

        $this->assertCount(0, $paid);
        $this->assertSame('cancelled', $order->fresh()->status);
        $released = $submission->fresh();
        $this->assertNull($released->order_id);
        $this->assertFalse($released->isInUse());
        $this->assertFalse($released->isClaimedByAnotherOrder());
        $this->assertTrue($released->isReadyForCheckout());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->checkoutReady()->exists()
        );
    }

    public function test_mark_paid_refunds_when_library_article_was_deleted(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'legacy-deleted.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $submission = $this->createApprovedSubmission($advertiser);
        $ref = 'LEGACY-DELETED-1';

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_submission_id' => $submission->id,
            'content_path' => $submission->path,
            'content_original_name' => $submission->original_filename,
            'content_link' => 'https://example.com/a',
            'anchor_text' => 'stale anchor',
            'target_url' => 'https://stale.example/old',
            'price' => 80,
        ]);

        $submission->delete();

        $paid = app(OrderPaymentService::class)->markOrdersPaidFromStripeSession(
            $ref,
            $this->paidSession($ref, 80, 'cs_legacy_deleted')
        );

        $this->assertCount(0, $paid);
        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('refunded', $fresh->payment_status);

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, app(OrderPaymentService::class)->refundedCardOrderAmount($ref), 0.01);
    }

    public function test_mark_paid_refreshes_stale_library_links_from_the_live_article(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'legacy-refresh.example', 80);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'anchor_text' => 'live anchor',
            'target_url' => 'https://example.com/live',
        ]);
        $ref = 'LEGACY-REFRESH-1';

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_submission_id' => $submission->id,
            'content_link' => 'https://example.com/a',
            'anchor_text' => 'stale anchor',
            'target_url' => 'https://stale.example/old',
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $paid = app(OrderPaymentService::class)->markOrdersPaidFromStripeSession(
            $ref,
            $this->paidSession($ref, 80, 'cs_legacy_refresh')
        );

        $this->assertCount(1, $paid);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $item->refresh();
        $this->assertSame('live anchor', $item->anchor_text);
        $this->assertSame('https://example.com/live', $item->target_url);
    }

    public function test_mark_paid_refunds_when_linked_library_article_is_unready(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'legacy-unready.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $submission = $this->createApprovedSubmission($advertiser);
        $ref = 'LEGACY-UNREADY-1';

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_submission_id' => $submission->id,
            'content_link' => 'https://example.com/a',
            'anchor_text' => 'stale anchor',
            'target_url' => 'https://stale.example/old',
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'target_url' => null,
        ]);

        $paid = app(OrderPaymentService::class)->markOrdersPaidFromStripeSession(
            $ref,
            $this->paidSession($ref, 80, 'cs_legacy_unready')
        );

        $this->assertCount(0, $paid);
        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('refunded', $fresh->payment_status);
        $this->assertNull($submission->fresh()->order_id);

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
    }

    public function test_taken_content_library_line_is_refunded_once_not_double_credited(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $takenSite = $this->makeSite($publisher, 'taken-article.example', 80);
        $hidden = $this->makeSite($publisher, 'taken-hidden.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 0);

        $prior = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PRIOR-TAKEN-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $priorItem = OrderItem::create([
            'order_id' => $prior->id,
            'site_id' => $takenSite->id,
            'site_name' => $takenSite->site_name,
            'site_url' => $takenSite->site_url,
            'content_link' => 'https://example.com/prior',
            'price' => 80,
        ]);
        $submission = $this->createApprovedSubmission($advertiser, $takenSite->id);
        $submission->update([
            'order_id' => $prior->id,
            'order_item_id' => $priorItem->id,
        ]);

        $ref = 'TAKEN-ARTICLE-1';
        $payments = app(OrderPaymentService::class);
        $takenLine = $this->lineFor($takenSite, 80);
        $takenLine['content_submission_id'] = $submission->id;
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $takenLine,
            $this->lineFor($hidden, 40),
        ], 120));

        $hidden->update(['verified' => false, 'active' => false]);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 120, 'cs_taken_article')
        );

        $this->assertCount(0, $created);
        $refunded = Order::query()
            ->where('reference_code', $ref)
            ->where('payment_status', 'refunded')
            ->get();
        $this->assertCount(1, $refunded);
        $this->assertSame('cancelled', $refunded->first()->status);
        $this->assertSame($prior->id, (int) $submission->fresh()->order_id);

        $wallet->refresh();
        $this->assertEqualsWithDelta(120.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(40.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->refundedCardOrderAmount($ref), 0.01);
        $this->assertEqualsWithDelta(120.0, $payments->walletCreditForUnfulfillableCardCheckout($ref), 0.01);
    }

    public function test_unready_content_library_line_is_refunded_on_finalize(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'unready-article.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $submission = $this->createApprovedSubmission($advertiser);

        $ref = 'UNREADY-ARTICLE-1';
        $payments = app(OrderPaymentService::class);
        $line = $this->lineFor($site, 80);
        $line['content_submission_id'] = $submission->id;
        $payments->storePendingCheckout($ref, $this->package($advertiser, [$line], 80));

        $submission->update(['target_url' => null]);
        $this->assertFalse($submission->fresh()->isReadyForCheckout());

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 80, 'cs_unready_article')
        );

        $this->assertCount(0, $created);
        $refunded = Order::query()
            ->where('reference_code', $ref)
            ->where('payment_status', 'refunded')
            ->get();
        $this->assertCount(1, $refunded);
        $this->assertSame('cancelled', $refunded->first()->status);
        $this->assertNull($submission->fresh()->order_id);

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->refundedCardOrderAmount($ref), 0.01);
    }

    public function test_deleted_content_library_line_is_refunded_on_finalize(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'deleted-article.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $submission = $this->createApprovedSubmission($advertiser);

        $ref = 'DELETED-ARTICLE-1';
        $payments = app(OrderPaymentService::class);
        $line = $this->lineFor($site, 80);
        $line['content_submission_id'] = $submission->id;
        $payments->storePendingCheckout($ref, $this->package($advertiser, [$line], 80));

        $submission->delete();

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 80, 'cs_deleted_article')
        );

        $this->assertCount(0, $created);
        $refunded = Order::query()
            ->where('reference_code', $ref)
            ->where('payment_status', 'refunded')
            ->get();
        $this->assertCount(1, $refunded);
        $this->assertSame('cancelled', $refunded->first()->status);

        $item = $refunded->first()->items()->first();
        $this->assertNotNull($item);
        $this->assertNull($item->content_submission_id);
        $this->assertNull($item->anchor_text);
        $this->assertNull($item->target_url);

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->refundedCardOrderAmount($ref), 0.01);
    }

    public function test_wallet_deposit_session_cannot_materialize_orders(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'wallet-collide.example', 50);
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('COLLIDE-1', $this->package($advertiser, [
            $this->lineFor($site, 50),
        ], 50));

        $walletSession = (object) [
            'id' => 'cs_wallet_collide',
            'object' => 'checkout.session',
            'amount_total' => 5000,
            'payment_intent' => 'pi_wallet_collide',
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'reference_code' => 'COLLIDE-1',
                'user_id' => (string) $advertiser->id,
                'amount' => '50',
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an order payment');
        $payments->finalizeStripeFirstCheckout('COLLIDE-1', $walletSession);
    }

    public function test_wallet_deposit_session_cannot_mark_existing_card_orders_paid(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'wallet-mark-paid.example', 50);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'COLLIDE-PAID',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article',
        ]);

        $walletSession = (object) [
            'id' => 'cs_wallet_mark',
            'object' => 'checkout.session',
            'amount_total' => 5000,
            'payment_intent' => 'pi_wallet_mark',
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'reference_code' => 'COLLIDE-PAID',
                'expected_amount' => '50',
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an order payment');
        app(OrderPaymentService::class)->markOrdersPaidFromStripeSession('COLLIDE-PAID', $walletSession);
    }

    public function test_webhook_settles_without_retry_when_every_line_left_the_catalog(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $hidden = $this->makeSite($publisher, 'webhook-left-catalog.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'WH-LEFT-CATALOG-1';
        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($hidden, 80),
        ], 80));

        $hidden->update(['verified' => false, 'active' => false]);

        $this->signedWebhook([
            'id' => 'evt_left_catalog_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_wh_left_catalog',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_wh_left',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(0, Order::query()->where('reference_code', $ref)->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_webhook_settles_when_content_library_line_was_already_taken(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'webhook-taken.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);

        $prior = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PRIOR-WH-TAKEN',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $submission->update(['order_id' => $prior->id]);

        $ref = 'WH-TAKEN-ARTICLE-1';
        $line = $this->lineFor($site, 80);
        $line['content_submission_id'] = $submission->id;
        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package($advertiser, [$line], 80));

        $this->signedWebhook([
            'id' => 'evt_taken_article_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_wh_taken_article',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_wh_taken',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame('refunded', Order::query()->where('reference_code', $ref)->value('payment_status'));
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, app(OrderPaymentService::class)->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_cancel_url_keeps_package_so_late_webhook_can_settle(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'cancel-keep-pkg.example', 80);
        $ref = 'CANCEL-KEEP-PKG-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 80));

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => $ref]))
            ->assertRedirect();

        $this->assertNotNull($payments->getPendingCheckout($ref));
        $this->assertSame(0, Order::query()->where('reference_code', $ref)->count());

        $this->signedWebhook([
            'id' => 'evt_cancel_keep_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_cancel_keep_pkg',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_cancel_keep',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, Order::query()->where('reference_code', $ref)->where('payment_status', 'paid')->count());
    }

    public function test_cancel_url_late_pay_re_reserves_bonus_and_refunds_only_card_slice(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'cancel-bonus-late.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'CANCEL-BONUS-LATE-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 60, 20));

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => $ref]))
            ->assertRedirect();

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);

        $this->signedWebhook([
            'id' => 'evt_cancel_bonus_late_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_cancel_bonus_late',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 6000,
                    'payment_intent' => 'pi_cancel_bonus_late',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '60',
                        'bonus_applied' => '20',
                    ],
                ],
            ],
        ])->assertOk();

        $order = Order::query()->where('reference_code', $ref)->where('payment_status', 'paid')->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(80.0, (float) $order->total_amount, 0.01);

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);

        app(OrderRefundService::class)->cancelAndRefund($order);
        $wallet->refresh();
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(20.0, $wallet->lockedBonusBalance(), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_cancel_url_late_pay_credits_card_when_bonus_was_spent(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'cancel-bonus-spent.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'CANCEL-BONUS-SPENT-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 60, 20));

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => $ref]))
            ->assertRedirect();

        $wallet->refresh();
        $wallet->update([
            'balance' => 0,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
        ]);

        $this->signedWebhook([
            'id' => 'evt_cancel_bonus_spent_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_cancel_bonus_spent',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 6000,
                    'payment_intent' => 'pi_cancel_bonus_spent',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '60',
                        'bonus_applied' => '20',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(0, Order::query()->where('reference_code', $ref)->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(60.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(60.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_paid_webhook_credits_wallet_when_package_was_forgotten(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'FORGOTTEN-PKG-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($this->makeSite($this->makeUser('publisher'), 'forgotten-pkg.example', 80), 80),
        ], 80));
        $payments->forgetPendingCheckout($ref);

        $this->signedWebhook([
            'id' => 'evt_forgotten_pkg_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_forgotten_pkg',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_forgotten_pkg',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(0, Order::query()->where('reference_code', $ref)->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_finalize_materializes_expired_checkout_intent_after_cache_flush(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'expired-intent.example', 80);
        $ref = 'EXPIRED-INTENT-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 80));

        CheckoutIntent::query()->where('reference_code', $ref)->update([
            'expires_at' => now()->subHour(),
        ]);
        Cache::flush();

        $this->assertNotNull($payments->getPendingCheckout($ref));

        $this->signedWebhook([
            'id' => 'evt_expired_intent_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_expired_intent',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_expired_intent',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, Order::query()->where('reference_code', $ref)->where('payment_status', 'paid')->count());
        $this->assertSame($site->id, (int) OrderItem::query()->whereHas('order', function ($q) use ($ref) {
            $q->where('reference_code', $ref);
        })->value('site_id'));
    }

    public function test_reused_reference_with_new_package_materializes_second_checkout(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $first = $this->makeSite($publisher, 'reuse-first.example', 40);
        $second = $this->makeSite($publisher, 'reuse-second.example', 80);
        $ref = 'REUSE-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($first, 40),
        ], 40));

        $firstPaid = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 40, 'cs_reuse_first')
        );
        $this->assertCount(1, $firstPaid);
        $this->assertNull($payments->getPendingCheckout($ref));

        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($second, 80),
        ], 80));

        $this->signedWebhook([
            'id' => 'evt_reuse_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_reuse_second',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_reuse_second',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(2, Order::query()->where('reference_code', $ref)->where('payment_status', 'paid')->count());
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            OrderItem::query()
                ->whereIn('order_id', Order::query()->where('reference_code', $ref)->pluck('id'))
                ->pluck('site_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_release_abandoned_bonus_lets_a_retry_reserve_again(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'retry-bonus.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'RETRY-BONUS-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 60, 20));

        $this->assertEqualsWithDelta(0.0, (float) $wallet->fresh()->lockedBonusBalance(), 0.01);

        $payments->releaseAbandonedStripeFirstBonus($advertiser->id, $ref);

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertNotNull($payments->getPendingCheckout($ref));

        $this->assertEqualsWithDelta(20.0, $wallet->reserveBonusOnly(20), 0.01);
    }

    public function test_release_abandoned_bonus_does_not_touch_paid_checkout_hold(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);

        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PAID-HOLD-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);

        app(OrderPaymentService::class)->releaseAbandonedStripeFirstBonus($advertiser->id, 'NEW-REF-1');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_release_abandoned_bonus_does_not_steal_open_stripe_session_hold(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'open-session-hold.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'OPEN-SESSION-HOLD-1';
        $payments = app(OrderPaymentService::class);
        $package = $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 60, 20);
        $package['stripe_session_id'] = 'cs_open_first';
        $payments->storePendingCheckout($ref, $package);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $ref, 20);

        $payments->releaseAbandonedStripeFirstBonus($advertiser->id, 'NEW-REF-1');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertNotNull($payments->getPendingCheckout($ref));

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 60, 'cs_open_first')
        );
        $this->assertCount(1, $created);
        $this->assertEqualsWithDelta(80.0, (float) $created->first()->total_amount, 0.01);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_release_abandoned_does_not_steal_from_cancelled_leftover_package_snapshot(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'cancelled-leftover-hold.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $payments = app(OrderPaymentService::class);
        $intents = app(CheckoutIntentService::class);

        $cancelledRef = 'CANCELLED-LEFTOVER-1';
        $payments->storePendingCheckout($cancelledRef, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 60, 20));
        $intents->rememberBonus($advertiser->id, $cancelledRef, 20);
        $wallet->reserveBonusOnly(20);

        app(OrderRefundService::class)->releaseReservedCheckoutBonusForReference(
            $advertiser->id,
            $cancelledRef,
            collect(),
            20
        );

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, $intents->heldBonus($advertiser->id, $cancelledRef), 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            (float) ($payments->getPendingCheckout($cancelledRef)['bonus_applied'] ?? 0),
            0.01
        );

        $liveRef = 'LIVE-CART-1';
        $livePackage = $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 60, 20);
        $livePackage['stripe_session_id'] = 'cs_live_second';
        $payments->storePendingCheckout($liveRef, $livePackage);
        $intents->rememberBonus($advertiser->id, $liveRef, 20);
        $wallet->reserveBonusOnly(20);

        $payments->releaseAbandonedStripeFirstBonus($advertiser->id, 'NEW-CART-1');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, $intents->heldBonus($advertiser->id, $liveRef), 0.01);
        $this->assertEqualsWithDelta(0.0, $intents->heldBonus($advertiser->id, $cancelledRef), 0.01);
        $this->assertNotNull($payments->getPendingCheckout($cancelledRef));
        $this->assertNotNull($payments->getPendingCheckout($liveRef));
    }

    public function test_release_abandoned_does_not_steal_pending_session_hold(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'pending-session-hold.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'PENDING-SESSION-HOLD-1';
        $payments = app(OrderPaymentService::class);
        $package = $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 60, 20);
        $package['stripe_session_id'] = OrderPaymentService::PENDING_STRIPE_SESSION_ID;
        $payments->storePendingCheckout($ref, $package);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $ref, 20);

        $payments->releaseAbandonedStripeFirstBonus($advertiser->id, 'NEW-REF-1');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertNotNull($payments->getPendingCheckout($ref));
        $this->assertSame(
            OrderPaymentService::PENDING_STRIPE_SESSION_ID,
            $payments->getPendingCheckout($ref)['stripe_session_id'] ?? null
        );
    }

    public function test_stale_cheaper_session_credits_card_and_leaves_package_for_matching_pay(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'stale-cheap.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'STALE-CHEAP-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 80));

        $stale = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 50, 'cs_stale_cheap')
        );

        $this->assertCount(0, $stale);
        $this->assertSame(0, Order::query()->where('reference_code', $ref)->count());
        $this->assertNotNull($payments->getPendingCheckout($ref));
        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(50.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 80, 'cs_current_full')
        );

        $this->assertCount(1, $created);
        $this->assertEqualsWithDelta(80.0, (float) $created->first()->total_amount, 0.01);
        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
    }

    public function test_second_session_after_paid_orders_credits_card_once(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'dup-session.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'DUP-SESSION-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 80));

        $first = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 80, 'cs_dup_first')
        );
        $this->assertCount(1, $first);
        $this->assertNull($payments->getPendingCheckout($ref));

        $second = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 80, 'cs_dup_second')
        );
        $this->assertCount(0, $second);
        $this->assertSame(1, Order::query()->where('reference_code', $ref)->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);

        $replay = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 80, 'cs_dup_first')
        );
        $this->assertCount(0, $replay);
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_webhook_credits_second_session_after_orders_exist(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'wh-dup-session.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'WH-DUP-SESSION-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($site, 80),
        ], 80));

        $payments->finalizeStripeFirstCheckout($ref, $this->paidSession($ref, 80, 'cs_wh_dup_first'));
        $this->assertNull($payments->getPendingCheckout($ref));

        $this->signedWebhook([
            'id' => 'evt_wh_dup_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_wh_dup_second',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_wh_dup_second',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, Order::query()->where('reference_code', $ref)->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_unfulfilled_credit_is_not_doubled_on_success_url_after_webhook(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $hidden = $this->makeSite($publisher, 'double-credit.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'DOUBLE-CREDIT-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($hidden, 80),
        ], 80));
        $hidden->update(['verified' => false, 'active' => false]);

        $session = $this->paidSession($ref, 80, 'cs_double_credit');
        $this->assertCount(0, $payments->finalizeStripeFirstCheckout($ref, $session));
        $this->assertCount(0, $payments->finalizeStripeFirstCheckout($ref, $session));

        $this->assertSame(0, Order::query()->where('reference_code', $ref)->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_suffixed_unfulfilled_credit_does_not_stack_on_legacy_unsuffixed_row(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'LEGACY-UNFULFILLED-1';
        $payments = app(OrderPaymentService::class);

        $this->assertEqualsWithDelta(60.0, $payments->creditUnfulfilledCardCapture($advertiser->id, $ref, 60), 0.01);
        $this->assertEqualsWithDelta(0.0, $payments->creditUnfulfilledCardCapture($advertiser->id, $ref, 60, 'cs_legacy_unfulfilled'), 0.01);

        $wallet->refresh();
        $this->assertEqualsWithDelta(60.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(60.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }
}
