<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OrderPaymentService;
use App\Services\StripeCustomerService;
use App\Services\Wallet\WalletLedgerService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Stripe\Checkout\Session;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CancelledCardOrderMarkPaidTest extends TestCase
{
    use CreatesContentSubmissions;
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

        return $user->fresh();
    }

    private function makeSite(User $publisher, string $domain = 'cancelled-card.example'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Cancelled Card Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Cancelled card order site. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_stripe_session_does_not_revive_a_cancelled_card_order(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-CANCELLED-CARD',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'cancelled',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 80,
        ]);

        $session = (object) [
            'id' => 'cs_cancelled_card',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_cancelled_card',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-CANCELLED-CARD',
                'expected_amount' => '80',
            ],
        ];

        $paid = app(OrderPaymentService::class)
            ->markOrdersPaidFromStripeSession('REF-CANCELLED-CARD', $session);

        $this->assertTrue($paid->isEmpty());
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->paid_at);
    }

    public function test_new_checkout_fails_and_cancels_conflicting_unpaid_card_orders(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 20,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'cancelled card anchor',
            'https://example.com/target'
        );

        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-CARD',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);

        Cache::put('checkout_bonus:'.$advertiser->id.':REF-STALE-CARD', 20, now()->addHour());

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-NEW-WALLET',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $stale->refresh();
        $this->assertSame('cancelled', $stale->status);
        $this->assertSame('failed', $stale->payment_status);

        $wallet = Wallet::where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_new_checkout_replaces_abandoned_wise_leftover(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'wise leftover anchor',
            'https://example.com/target'
        );

        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertFalse($submission->fresh()->isReadyForCheckout());

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-NEW-WALLET-WISE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $stale->refresh();
        $this->assertSame('cancelled', $stale->status);
        $this->assertSame('failed', $stale->payment_status);

        $paid = Order::query()->where('reference_code', 'REF-NEW-WALLET-WISE')->first();
        $this->assertNotNull($paid);
        $this->assertSame($paid->id, (int) $submission->fresh()->order_id);

        ContentSubmission::releaseAllForOrder((int) $stale->id);
        $this->assertSame($paid->id, (int) $submission->fresh()->order_id);
        $this->assertSame(
            (int) OrderItem::query()->where('order_id', $paid->id)->value('id'),
            (int) $submission->fresh()->order_item_id
        );
    }

    public function test_order_from_library_releases_abandoned_wise_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE-LIB',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $submission))
            ->assertRedirect(route('advertiser.catalog', [
                'content_submission_id' => $submission->id,
            ]));

        $stale->refresh();
        $this->assertSame('cancelled', $stale->status);
        $this->assertNull($submission->fresh()->order_id);
        $this->assertTrue($submission->fresh()->isReadyForCheckout());
    }

    public function test_new_checkout_replaces_failed_card_leftover(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'failed card leftover anchor',
            'https://example.com/target'
        );
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-CARD',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertFalse($submission->fresh()->isReadyForCheckout());
        $this->assertTrue($submission->fresh()->load('order')->canReplaceUnpaidLeftover());

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-NEW-WALLET-FAILED-CARD',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $leftover->refresh();
        $this->assertSame('cancelled', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);

        $paid = Order::query()->where('reference_code', 'REF-NEW-WALLET-FAILED-CARD')->first();
        $this->assertNotNull($paid);
        $this->assertSame($paid->id, (int) $submission->fresh()->order_id);

        ContentSubmission::releaseAllForOrder((int) $leftover->id);
        $this->assertSame($paid->id, (int) $submission->fresh()->order_id);
        $this->assertSame(
            (int) OrderItem::query()->where('order_id', $paid->id)->value('id'),
            (int) $submission->fresh()->order_item_id
        );
    }

    public function test_library_shows_order_for_abandoned_wise_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Stuck Wise Piece']);
        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE-UI',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertTrue($submission->fresh()->load('order')->canReplaceUnpaidLeftover());
        $this->assertSame('in_progress', $submission->fresh()->libraryAvailability());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Stuck Wise Piece')
            ->assertSee(route('advertiser.content-library.order', $submission, false), false)
            ->assertSee('View order');
    }

    public function test_library_does_not_offer_order_for_paid_in_progress(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Paid Processing Piece']);
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PAID-PROGRESS',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertFalse($submission->fresh()->load('order')->canReplaceUnpaidLeftover());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Paid Processing Piece')
            ->assertSee('View order')
            ->assertDontSee(route('advertiser.content-library.order', $submission, false), false);
    }

    public function test_cart_picker_keeps_abandoned_wise_leftover_assignment(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Picker Wise Piece']);
        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE-PICKER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertTrue($submission->fresh()->load('order')->isAvailableForPicker());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->availableForPicker()->exists()
        );

        $payload = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $submission->id,
                    'content_submission_ids' => [$submission->id],
                ]],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json();

        $articleIds = collect($payload['approved_articles'] ?? [])->pluck('id')->all();
        $this->assertContains($submission->id, $articleIds);
        $this->assertSame($submission->id, (int) ($payload['cart'][0]['content_submission_id'] ?? 0));
        $this->assertSame('pending', $stale->fresh()->status);
    }

    public function test_cart_picker_keeps_failed_card_leftover_assignment(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $ready = $this->createApprovedSubmission($advertiser);
        $ready->update(['title' => 'Free Ready Piece']);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Failed Card Piece']);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-CARD-PICKER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertTrue($submission->fresh()->load('order')->isAvailableForPicker());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->availableForPicker()->exists()
        );

        $payload = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $submission->id,
                    'content_submission_ids' => [$submission->id],
                ]],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json();

        $articleIds = collect($payload['approved_articles'] ?? [])->pluck('id')->all();
        $this->assertContains($ready->id, $articleIds);
        $this->assertContains($submission->id, $articleIds);
        $this->assertSame($submission->id, (int) ($payload['cart'][0]['content_submission_id'] ?? 0));
        $this->assertSame('pending', $leftover->fresh()->status);
        $this->assertSame('failed', $leftover->fresh()->payment_status);
    }

    public function test_library_shows_order_for_failed_card_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Stuck Failed Card Piece']);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-CARD-UI',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertTrue($submission->fresh()->load('order')->canReplaceUnpaidLeftover());
        $this->assertSame('in_progress', $submission->fresh()->libraryAvailability());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Stuck Failed Card Piece')
            ->assertSee(route('advertiser.content-library.order', $submission, false), false)
            ->assertSee('View order');
    }

    public function test_order_from_library_releases_failed_card_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-CARD-LIB',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $submission))
            ->assertRedirect(route('advertiser.catalog', [
                'content_submission_id' => $submission->id,
            ]));

        $leftover->refresh();
        $this->assertSame('cancelled', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertNull($submission->fresh()->order_id);
        $this->assertTrue($submission->fresh()->isReadyForCheckout());
    }

    public function test_order_from_library_keeps_unready_leftover_for_pay_again(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Broken Link Leftover',
            'target_url' => null,
        ]);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-UNREADY-LEFTOVER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $fresh = $submission->fresh()->load('order');
        $this->assertTrue($fresh->canReplaceUnpaidLeftover());
        $this->assertFalse($fresh->isContentReadyForOrder());
        $this->assertFalse($fresh->canOrderFromLibrary());
        $this->assertSame('needs_fix', $fresh->libraryAvailability());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $submission))
            ->assertRedirect(route('advertiser.content-library'));

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
        $this->assertTrue($submission->fresh()->load('order')->canReplaceUnpaidLeftover());
    }

    public function test_library_hides_order_for_unready_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Fix Links Then Pay Again',
            'target_url' => null,
        ]);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-UNREADY-LEFTOVER-UI',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Fix Links Then Pay Again')
            ->assertSee('View order')
            ->assertSee('Edit article')
            ->assertDontSee(route('advertiser.content-library.order', $submission, false), false);
    }

    public function test_expired_leftover_stays_ready_to_pay_again(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-EXPIRED-PAY-AGAIN',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
            'expires_at' => now()->subDay(),
        ]);

        $fresh = $submission->fresh()->load(['order', 'orderItems.order']);
        $this->assertTrue($fresh->isExpired());
        $this->assertFalse($fresh->isContentReadyForOrder());
        $this->assertFalse($fresh->canOrderFromLibrary());
        $this->assertTrue($fresh->isReadyToFulfill((int) $leftover->id));
        $this->assertTrue($fresh->canReplaceUnpaidLeftover());
    }

    public function test_order_from_library_sends_expired_leftover_to_pay_again(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Expired Leftover Keep Pay Again',
            'expires_at' => now()->subDay(),
        ]);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-EXPIRED-ORDER-CTA',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->from(route('advertiser.content-library'))
            ->get(route('advertiser.content-library.order', $submission))
            ->assertRedirect(route('advertiser.orders'))
            ->assertSessionHas('error', function ($message) {
                return is_string($message) && str_contains($message, 'Pay again');
            });

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
        $this->assertTrue($submission->fresh()->load('order')->isReadyToFulfill((int) $leftover->id));
    }

    public function test_checkout_cancel_url_does_not_cancel_failed_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-CANCEL-URL-KEEP',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', [
                'canceled' => 1,
                'ref' => 'REF-CANCEL-URL-KEEP',
            ]));

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
    }

    public function test_checkout_cancel_url_fails_pending_leftover_without_releasing_it(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-CANCEL-URL-PENDING',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', [
                'canceled' => 1,
                'ref' => 'REF-CANCEL-URL-PENDING',
            ]));

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
    }

    public function test_pay_again_cancel_marks_leftover_failed_without_releasing_it(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PAY-AGAIN-CANCEL',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
            'stripe_session_id' => 'cs_pay_again_cancel',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->withSession(['pending_card_reference' => 'REF-PAY-AGAIN-CANCEL'])
            ->get(route('advertiser.orders', [
                'payment_status' => 'failed',
                'retry' => 'canceled',
                'ref' => 'REF-PAY-AGAIN-CANCEL',
            ]))
            ->assertOk();

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
    }

    public function test_checkout_does_not_replace_unready_leftover_when_paying_another_line(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $readySite = $this->makeSite($publisher, 'ready-pay.example');
        $leftoverSite = $this->makeSite($publisher, 'unready-leftover.example');

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 200,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $ready = $this->createApprovedSubmission(
            $advertiser,
            $readySite->id,
            0,
            'ready checkout anchor',
            'https://example.com/ready'
        );
        $unready = $this->createApprovedSubmission(
            $advertiser,
            $leftoverSite->id,
            0,
            'broken leftover anchor',
            'https://example.com/broken'
        );
        $unready->update(['target_url' => null]);

        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-UNREADY-SIBLING',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $leftoverSite->id,
            'site_name' => $leftoverSite->site_name,
            'site_url' => $leftoverSite->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $unready->id,
            'price' => 80,
        ]);
        $unready->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $readySite->id, 'name' => $readySite->site_name, 'quantity' => 1],
                    ['id' => $leftoverSite->id, 'name' => $leftoverSite->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-PAY-READY-KEEP-UNREADY',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $readySite->id => [$ready->id],
                    $leftoverSite->id => [$unready->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $unready->fresh()->order_id);

        $paid = Order::query()->where('reference_code', 'REF-PAY-READY-KEEP-UNREADY')->first();
        $this->assertNotNull($paid);
        $this->assertSame($paid->id, (int) $ready->fresh()->order_id);
    }

    public function test_wise_checkout_does_not_cancel_ready_leftover(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-WISE-KEEP-LEFTOVER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wise',
                'reference_code' => 'REF-WISE-SHOULD-NOT-REPLACE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'fund_wallet_first');

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
    }

    public function test_wallet_insufficient_does_not_cancel_ready_leftover(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 5,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-WALLET-SHORT-LEFTOVER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-WALLET-SHORT-SHOULD-NOT-REPLACE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
        $this->assertNull(Order::query()->where('reference_code', 'REF-WALLET-SHORT-SHOULD-NOT-REPLACE')->first());
    }

    public function test_assign_language_mismatch_does_not_cancel_ready_leftover(): void
    {
        config([
            'content_moderation.enabled' => false,
            'content_upload.placement.require_same_language' => true,
        ]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'German Leftover Site',
            'site_url' => 'https://de-leftover.example',
            'domain' => 'de-leftover.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('German leftover site. ', 3),
            'verified' => true,
            'active' => true,
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-ASSIGN-LANG-LEFTOVER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'language' => 'de',
                    'country' => 'de',
                ]],
            ])
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $site->id,
                'content_submission_id' => $submission->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('language_mismatch', true);

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
    }

    public function test_catalog_query_does_not_release_abandoned_wise_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE-CATALOG',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', [
                'content_submission_id' => $submission->id,
            ]))
            ->assertOk();

        $stale->refresh();
        $this->assertSame('pending', $stale->status);
        $this->assertSame($stale->id, (int) $submission->fresh()->order_id);
        $this->assertSame($submission->id, (int) session('checkout_content_submission_id'));
        $this->assertTrue((bool) session('ordering_from_library'));
    }

    public function test_add_to_cart_language_mismatch_does_not_cancel_ready_leftover(): void
    {
        config([
            'content_moderation.enabled' => false,
            'content_upload.placement.require_same_language' => true,
        ]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'German Add Cart Site',
            'site_url' => 'https://de-add-cart.example',
            'domain' => 'de-add-cart.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('German add cart site. ', 3),
            'verified' => true,
            'active' => true,
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-ADD-CART-LANG-LEFTOVER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'ordering_from_library' => true,
                'checkout_content_submission_id' => $submission->id,
                'cart' => [],
            ])
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('language_mismatch', true);

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
    }

    public function test_invalid_schedule_does_not_cancel_ready_leftover(): void
    {
        config([
            'content_moderation.enabled' => false,
            'content_upload.scheduling.enabled' => true,
        ]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 200,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-BAD-SCHEDULE-LEFTOVER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-BAD-SCHEDULE-SHOULD-NOT-REPLACE',
                'publication_mode' => 'scheduled',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertStatus(422);

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
        $this->assertNull(Order::query()->where('reference_code', 'REF-BAD-SCHEDULE-SHOULD-NOT-REPLACE')->first());
    }

    public function test_stripe_session_failure_does_not_cancel_ready_leftover(): void
    {
        config([
            'content_moderation.enabled' => false,
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STRIPE-FAIL-KEEP',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->andThrow(new \RuntimeException('stripe unavailable'));
            $mock->shouldReceive('payWithSavedCard')->never();
        });

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'REF-STRIPE-FAIL-SHOULD-NOT-REPLACE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
        $this->assertNull(Cache::get('pending_card_checkout:REF-STRIPE-FAIL-SHOULD-NOT-REPLACE'));
        $this->assertNull(Order::query()->where('reference_code', 'REF-STRIPE-FAIL-SHOULD-NOT-REPLACE')->first());
    }

    public function test_saved_card_failure_does_not_cancel_ready_leftover(): void
    {
        config([
            'content_moderation.enabled' => false,
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-SAVED-FAIL-KEEP',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('payWithSavedCard')
                ->once()
                ->andThrow(new \RuntimeException('This card does not belong to your account.'));
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'payment_method_id' => 'pm_test_visa',
                'reference_code' => 'REF-SAVED-FAIL-SHOULD-NOT-REPLACE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
        $this->assertNull(Cache::get('pending_card_checkout:REF-SAVED-FAIL-SHOULD-NOT-REPLACE'));
    }

    public function test_card_checkout_replaces_ready_leftover_after_stripe_session(): void
    {
        config([
            'content_moderation.enabled' => false,
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STRIPE-OK-REPLACE',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->andReturn(Session::constructFrom([
                    'id' => 'cs_test_replace_leftover',
                    'object' => 'checkout.session',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_replace_leftover',
                ]));
            $mock->shouldReceive('payWithSavedCard')->never();
        });

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'REF-STRIPE-OK-SHOULD-REPLACE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('requires_payment', true);

        $leftover->refresh();
        $this->assertSame('cancelled', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertNull($submission->fresh()->order_id);
        $this->assertNotNull(Cache::get('pending_card_checkout:REF-STRIPE-OK-SHOULD-REPLACE'));
        $this->assertSame(
            'cs_test_replace_leftover',
            Cache::get('pending_card_checkout:REF-STRIPE-OK-SHOULD-REPLACE')['stripe_session_id'] ?? null
        );
    }

    public function test_wallet_settle_failure_does_not_cancel_ready_leftover(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 200,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-WALLET-LEDGER-KEEP',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->partialMock(WalletLedgerService::class, function ($mock) {
            $mock->shouldReceive('recordPurchase')
                ->once()
                ->andThrow(new \RuntimeException('ledger schema mismatch'));
        });

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-WALLET-LEDGER-SHOULD-NOT-REPLACE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $leftover->refresh();
        $this->assertSame('pending', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
        $this->assertNull(Order::query()->where('reference_code', 'REF-WALLET-LEDGER-SHOULD-NOT-REPLACE')->first());
    }

    public function test_late_stripe_webhook_after_replace_credits_wallet_instead_of_rematerializing(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'replace webhook anchor',
            'https://example.com/target'
        );
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-THEN-REPLACED',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-FAILED-THEN-REPLACED', [
            'user_id' => $advertiser->id,
            'order_total' => 80,
            'amount_due' => 80,
            'bonus_applied' => 0,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [[
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 80,
                'content_submission_id' => $submission->id,
                'content_link' => 'https://example.com/article',
            ]],
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-NEW-WALLET-AFTER-REPLACE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('cancelled', $leftover->fresh()->status);
        $this->assertNull($payments->getPendingCheckout('REF-FAILED-THEN-REPLACED'));

        $wallet = Wallet::where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $balanceAfterReplace = (float) $wallet->balance;

        $session = (object) [
            'id' => 'cs_late_after_replace',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_late_after_replace',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-FAILED-THEN-REPLACED',
                'expected_amount' => '80',
                'user_id' => (string) $advertiser->id,
            ],
        ];

        $created = $payments->finalizeStripeFirstCheckout('REF-FAILED-THEN-REPLACED', $session);

        $this->assertTrue($created->isEmpty());
        $this->assertSame(0, Order::query()
            ->where('reference_code', 'REF-FAILED-THEN-REPLACED')
            ->where('payment_status', 'paid')
            ->count());
        $this->assertSame('cancelled', $leftover->fresh()->status);

        $paid = Order::query()->where('reference_code', 'REF-NEW-WALLET-AFTER-REPLACE')->first();
        $this->assertNotNull($paid);
        $this->assertSame($paid->id, (int) $submission->fresh()->order_id);

        $wallet->refresh();
        $this->assertEqualsWithDelta($balanceAfterReplace + 80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount('REF-FAILED-THEN-REPLACED'), 0.01);
    }

    public function test_late_stripe_webhook_after_replace_without_package_credits_wallet(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'replace no package anchor',
            'https://example.com/target'
        );
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-NO-PACKAGE',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-NEW-WALLET-NO-PACKAGE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $payments = app(OrderPaymentService::class);
        $session = (object) [
            'id' => 'cs_late_no_package',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_late_no_package',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-FAILED-NO-PACKAGE',
                'expected_amount' => '80',
                'user_id' => (string) $advertiser->id,
            ],
        ];

        $created = $payments->finalizeStripeFirstCheckout('REF-FAILED-NO-PACKAGE', $session);

        $this->assertTrue($created->isEmpty());
        $this->assertSame('cancelled', $leftover->fresh()->status);
        $this->assertSame(0, Order::query()
            ->where('reference_code', 'REF-FAILED-NO-PACKAGE')
            ->where('payment_status', 'paid')
            ->count());
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount('REF-FAILED-NO-PACKAGE'), 0.01);
    }

    public function test_late_stripe_webhook_after_partial_replace_does_not_pay_sibling_leftover(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $siteA = $this->makeSite($publisher, 'partial-replace-a.example');
        $siteB = $this->makeSite($publisher, 'partial-replace-b.example');
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 200,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $articleA = $this->createApprovedSubmission(
            $advertiser,
            $siteA->id,
            0,
            'partial replace article a',
            'https://example.com/target-a'
        );
        $articleB = $this->createApprovedSubmission(
            $advertiser,
            $siteB->id,
            1,
            'partial replace article b',
            'https://example.com/target-b'
        );

        $orderA = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PARTIAL-REPLACE',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $itemA = OrderItem::create([
            'order_id' => $orderA->id,
            'site_id' => $siteA->id,
            'site_name' => $siteA->site_name,
            'site_url' => $siteA->site_url,
            'content_link' => 'https://example.com/article-a',
            'content_submission_id' => $articleA->id,
            'price' => 80,
        ]);
        $articleA->update([
            'order_id' => $orderA->id,
            'order_item_id' => $itemA->id,
        ]);

        $orderB = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PARTIAL-REPLACE',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $itemB = OrderItem::create([
            'order_id' => $orderB->id,
            'site_id' => $siteB->id,
            'site_name' => $siteB->site_name,
            'site_url' => $siteB->site_url,
            'content_link' => 'https://example.com/article-b',
            'content_submission_id' => $articleB->id,
            'price' => 80,
        ]);
        $articleB->update([
            'order_id' => $orderB->id,
            'order_item_id' => $itemB->id,
        ]);

        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-PARTIAL-REPLACE', [
            'user_id' => $advertiser->id,
            'order_total' => 160,
            'amount_due' => 160,
            'bonus_applied' => 0,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [
                [
                    'site_id' => $siteA->id,
                    'site_name' => $siteA->site_name,
                    'site_url' => $siteA->site_url,
                    'price' => 80,
                    'content_submission_id' => $articleA->id,
                    'content_link' => 'https://example.com/article-a',
                ],
                [
                    'site_id' => $siteB->id,
                    'site_name' => $siteB->site_name,
                    'site_url' => $siteB->site_url,
                    'price' => 80,
                    'content_submission_id' => $articleB->id,
                    'content_link' => 'https://example.com/article-b',
                ],
            ],
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $siteA->id, 'name' => $siteA->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-WALLET-PARTIAL-A',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $siteA->id => [$articleA->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('cancelled', $orderA->fresh()->status);
        $this->assertSame('pending', $orderB->fresh()->status);
        $this->assertSame('failed', $orderB->fresh()->payment_status);
        $this->assertNull($payments->getPendingCheckout('REF-PARTIAL-REPLACE'));
        $this->assertSame($orderB->id, (int) $articleB->fresh()->order_id);

        $wallet = Wallet::where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $balanceAfterReplace = (float) $wallet->balance;

        $session = (object) [
            'id' => 'cs_late_partial_replace',
            'object' => 'checkout.session',
            'amount_total' => 16000,
            'payment_intent' => 'pi_late_partial_replace',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-PARTIAL-REPLACE',
                'expected_amount' => '160',
                'user_id' => (string) $advertiser->id,
            ],
        ];

        $created = $payments->finalizeStripeFirstCheckout('REF-PARTIAL-REPLACE', $session);

        $this->assertTrue($created->isEmpty());
        $this->assertSame('cancelled', $orderA->fresh()->status);
        $this->assertSame('pending', $orderB->fresh()->status);
        $this->assertSame('failed', $orderB->fresh()->payment_status);
        $this->assertSame(0, Order::query()
            ->where('reference_code', 'REF-PARTIAL-REPLACE')
            ->where('payment_status', 'paid')
            ->count());

        $wallet->refresh();
        $this->assertEqualsWithDelta($balanceAfterReplace + 160.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(160.0, $payments->unfulfilledCardCreditAmount('REF-PARTIAL-REPLACE'), 0.01);
    }

    public function test_pay_again_after_partial_replace_still_marks_the_sibling_paid(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $siteA = $this->makeSite($publisher, 'pay-again-partial-a.example');
        $siteB = $this->makeSite($publisher, 'pay-again-partial-b.example');
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 200,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $articleA = $this->createApprovedSubmission(
            $advertiser,
            $siteA->id,
            0,
            'pay again partial a',
            'https://example.com/target-a'
        );
        $articleB = $this->createApprovedSubmission(
            $advertiser,
            $siteB->id,
            1,
            'pay again partial b',
            'https://example.com/target-b'
        );

        $orderA = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PARTIAL-PAY-AGAIN',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $itemA = OrderItem::create([
            'order_id' => $orderA->id,
            'site_id' => $siteA->id,
            'site_name' => $siteA->site_name,
            'site_url' => $siteA->site_url,
            'content_link' => 'https://example.com/article-a',
            'content_submission_id' => $articleA->id,
            'price' => 80,
        ]);
        $articleA->update([
            'order_id' => $orderA->id,
            'order_item_id' => $itemA->id,
        ]);

        $orderB = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PARTIAL-PAY-AGAIN',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $itemB = OrderItem::create([
            'order_id' => $orderB->id,
            'site_id' => $siteB->id,
            'site_name' => $siteB->site_name,
            'site_url' => $siteB->site_url,
            'content_link' => 'https://example.com/article-b',
            'content_submission_id' => $articleB->id,
            'price' => 80,
        ]);
        $articleB->update([
            'order_id' => $orderB->id,
            'order_item_id' => $itemB->id,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $siteA->id, 'name' => $siteA->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-WALLET-PARTIAL-PAY-AGAIN',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $siteA->id => [$articleA->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('cancelled', $orderA->fresh()->status);
        $this->assertSame('failed', $orderB->fresh()->payment_status);

        $payments = app(OrderPaymentService::class);
        $session = (object) [
            'id' => 'cs_pay_again_after_partial',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_pay_again_after_partial',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-PARTIAL-PAY-AGAIN',
                'expected_amount' => '80',
                'user_id' => (string) $advertiser->id,
                'bonus_applied' => '0',
            ],
        ];

        $created = $payments->finalizeStripeFirstCheckout('REF-PARTIAL-PAY-AGAIN', $session);

        $this->assertCount(1, $created);
        $this->assertSame('paid', $orderB->fresh()->payment_status);
        $this->assertSame('pending', $orderB->fresh()->status);
        $this->assertSame('cancelled', $orderA->fresh()->status);
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount('REF-PARTIAL-PAY-AGAIN'), 0.01);
    }

    public function test_pay_again_capture_marks_failed_sibling_when_other_line_already_paid(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $siteA = $this->makeSite($publisher, 'paid-sibling-a.example');
        $siteB = $this->makeSite($publisher, 'failed-sibling-b.example');
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $articleA = $this->createApprovedSubmission(
            $advertiser,
            $siteA->id,
            0,
            'paid sibling article a',
            'https://example.com/target-a'
        );
        $articleB = $this->createApprovedSubmission(
            $advertiser,
            $siteB->id,
            1,
            'failed sibling article b',
            'https://example.com/target-b'
        );

        $orderA = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PAID-SIBLING-PAY-AGAIN',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        $itemA = OrderItem::create([
            'order_id' => $orderA->id,
            'site_id' => $siteA->id,
            'site_name' => $siteA->site_name,
            'site_url' => $siteA->site_url,
            'content_link' => 'https://example.com/article-a',
            'content_submission_id' => $articleA->id,
            'price' => 80,
        ]);
        $articleA->update([
            'order_id' => $orderA->id,
            'order_item_id' => $itemA->id,
        ]);

        $orderB = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PAID-SIBLING-PAY-AGAIN',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $itemB = OrderItem::create([
            'order_id' => $orderB->id,
            'site_id' => $siteB->id,
            'site_name' => $siteB->site_name,
            'site_url' => $siteB->site_url,
            'content_link' => 'https://example.com/article-b',
            'content_submission_id' => $articleB->id,
            'price' => 80,
        ]);
        $articleB->update([
            'order_id' => $orderB->id,
            'order_item_id' => $itemB->id,
        ]);

        $payments = app(OrderPaymentService::class);
        $session = (object) [
            'id' => 'cs_pay_again_paid_sibling',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_pay_again_paid_sibling',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-PAID-SIBLING-PAY-AGAIN',
                'expected_amount' => '80',
                'user_id' => (string) $advertiser->id,
                'bonus_applied' => '0',
                'is_retry' => '1',
            ],
        ];

        $created = $payments->finalizeStripeFirstCheckout('REF-PAID-SIBLING-PAY-AGAIN', $session);

        $this->assertCount(1, $created);
        $this->assertSame('paid', $orderA->fresh()->payment_status);
        $this->assertSame('paid', $orderB->fresh()->payment_status);
        $this->assertSame('pending', $orderB->fresh()->status);
        $this->assertSame($orderB->id, (int) $articleB->fresh()->order_id);
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount('REF-PAID-SIBLING-PAY-AGAIN'), 0.01);
        $wallet = Wallet::query()
            ->where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
    }

    public function test_late_stripe_webhook_after_wallet_claim_does_not_fulfill_package_sibling(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $siteA = $this->makeSite($publisher, 'package-claim-a.example');
        $siteB = $this->makeSite($publisher, 'package-claim-b.example');
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 200,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $articleA = $this->createApprovedSubmission(
            $advertiser,
            $siteA->id,
            0,
            'package claim article a',
            'https://example.com/target-a'
        );
        $articleB = $this->createApprovedSubmission(
            $advertiser,
            $siteB->id,
            1,
            'package claim article b',
            'https://example.com/target-b'
        );

        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-STRIPE-FIRST-AB', [
            'user_id' => $advertiser->id,
            'order_total' => 160,
            'amount_due' => 160,
            'bonus_applied' => 0,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [
                [
                    'site_id' => $siteA->id,
                    'site_name' => $siteA->site_name,
                    'site_url' => $siteA->site_url,
                    'price' => 80,
                    'content_submission_id' => $articleA->id,
                    'content_link' => 'https://example.com/article-a',
                ],
                [
                    'site_id' => $siteB->id,
                    'site_name' => $siteB->site_name,
                    'site_url' => $siteB->site_url,
                    'price' => 80,
                    'content_submission_id' => $articleB->id,
                    'content_link' => 'https://example.com/article-b',
                ],
            ],
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $siteA->id, 'name' => $siteA->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-WALLET-PACKAGE-A',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $siteA->id => [$articleA->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull($payments->getPendingCheckout('REF-STRIPE-FIRST-AB'));
        $this->assertNull($articleB->fresh()->order_id);

        $wallet = Wallet::where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $balanceAfterWallet = (float) $wallet->balance;

        $session = (object) [
            'id' => 'cs_late_package_claim',
            'object' => 'checkout.session',
            'amount_total' => 16000,
            'payment_intent' => 'pi_late_package_claim',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-STRIPE-FIRST-AB',
                'expected_amount' => '160',
                'user_id' => (string) $advertiser->id,
            ],
        ];

        $created = $payments->finalizeStripeFirstCheckout('REF-STRIPE-FIRST-AB', $session);

        $this->assertTrue($created->isEmpty());
        $this->assertSame(0, Order::query()
            ->where('reference_code', 'REF-STRIPE-FIRST-AB')
            ->count());
        $this->assertNull($articleB->fresh()->order_id);

        $wallet->refresh();
        $this->assertEqualsWithDelta($balanceAfterWallet + 160.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(160.0, $payments->unfulfilledCardCreditAmount('REF-STRIPE-FIRST-AB'), 0.01);
    }

    public function test_late_webhook_with_package_and_leftover_marks_leftover_once(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'package-and-leftover.example');
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'package leftover article',
            'https://example.com/target'
        );
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PACKAGE-AND-LEFTOVER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-PACKAGE-AND-LEFTOVER', [
            'user_id' => $advertiser->id,
            'order_total' => 80,
            'amount_due' => 80,
            'bonus_applied' => 0,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [[
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 80,
                'content_submission_id' => $submission->id,
                'content_link' => 'https://example.com/article',
            ]],
        ]);

        $wallet = Wallet::where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $balanceBefore = (float) $wallet->balance;

        $session = (object) [
            'id' => 'cs_package_and_leftover',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_package_and_leftover',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-PACKAGE-AND-LEFTOVER',
                'expected_amount' => '80',
                'user_id' => (string) $advertiser->id,
                'bonus_applied' => '0',
            ],
        ];

        $created = $payments->finalizeStripeFirstCheckout('REF-PACKAGE-AND-LEFTOVER', $session);

        $this->assertCount(1, $created);
        $this->assertSame($leftover->id, (int) $created->first()->id);
        $this->assertSame('paid', $leftover->fresh()->payment_status);
        $this->assertSame(1, Order::query()->where('reference_code', 'REF-PACKAGE-AND-LEFTOVER')->count());
        $this->assertSame($leftover->id, (int) $submission->fresh()->order_id);
        $this->assertNull($payments->getPendingCheckout('REF-PACKAGE-AND-LEFTOVER'));

        $wallet->refresh();
        $this->assertEqualsWithDelta($balanceBefore, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount('REF-PACKAGE-AND-LEFTOVER'), 0.01);
    }

    public function test_pay_again_session_marks_leftover_when_stale_package_session_differs(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'stale-package-pay-again.example');
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'stale package pay again',
            'https://example.com/target'
        );
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-PKG-PAY-AGAIN',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('REF-STALE-PKG-PAY-AGAIN', [
            'user_id' => $advertiser->id,
            'order_total' => 80,
            'amount_due' => 80,
            'bonus_applied' => 0,
            'stripe_session_id' => 'cs_abandoned_original',
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [[
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 80,
                'content_submission_id' => $submission->id,
                'content_link' => 'https://example.com/article',
            ]],
        ]);

        $wallet = Wallet::where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $balanceBefore = (float) $wallet->balance;

        $session = (object) [
            'id' => 'cs_pay_again_retry',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_pay_again_retry',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-STALE-PKG-PAY-AGAIN',
                'expected_amount' => '80',
                'user_id' => (string) $advertiser->id,
                'bonus_applied' => '0',
            ],
        ];

        $created = $payments->finalizeStripeFirstCheckout('REF-STALE-PKG-PAY-AGAIN', $session);

        $this->assertCount(1, $created);
        $this->assertSame($leftover->id, (int) $created->first()->id);
        $this->assertSame('paid', $leftover->fresh()->payment_status);
        $this->assertSame(1, Order::query()->where('reference_code', 'REF-STALE-PKG-PAY-AGAIN')->count());
        $this->assertNull($payments->getPendingCheckout('REF-STALE-PKG-PAY-AGAIN'));

        $wallet->refresh();
        $this->assertEqualsWithDelta($balanceBefore, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount('REF-STALE-PKG-PAY-AGAIN'), 0.01);
    }
}
