<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutSchemaService;
use App\Services\LiveUrlHealthChecker;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Happy path: publisher submits live URL → advertiser Approves → order completes.
 *
 * Also guards the Hostinger schema-drift failure where a missing
 * sites.completed_orders_count used to abort Approve mid-transaction.
 */
class AdvertiserApproveAfterLiveUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->swap(LiveUrlHealthChecker::class, new class extends LiveUrlHealthChecker
        {
            public function check(string $url): array
            {
                return ['ok' => true, 'status' => 200, 'checked_at' => now(), 'message' => 'OK'];
            }
        });
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $roleModel->id]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    /**
     * @return array{0: User, 1: User, 2: Site, 3: Order, 4: OrderItem}
     */
    private function paidProcessingOrder(float $price = 115.0): array
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advertiser->active_role_id,
            'balance' => 0,
            'reserved_balance' => $price,
        ]);
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisher->active_role_id,
            'balance' => 0,
            'reserved_balance' => 0,
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Approve After Live URL Site',
            'site_url' => 'https://approve-after-live.example',
            'domain' => 'approve-after-live.example',
            'da' => 30, 'dr' => 35, 'traffic' => 4000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => $price,
            'turnaround_time' => '3days',
            'publication_time' => '5 days', 'link_type' => 'dofollow',
            'description' => 'Test site', 'verified' => true, 'active' => true,
            'completed_orders_count' => 0,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-AAL-'.uniqid(),
            'reference_code' => 'REF-AAL-'.uniqid(),
            'subtotal' => $price, 'tax' => 0, 'total_amount' => $price,
            'payment_method' => 'wallet', 'payment_status' => 'paid',
            'status' => 'processing', 'paid_at' => now(),
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => $price,
            'publisher_price' => round($price / OrderItem::PLATFORM_MARKUP_RATE, 2),
            'content_link' => 'https://example.com/article.docx',
            'accepted_at' => now()->subHours(2),
            'publisher_status' => 'accepted',
            'modification_requested' => 'no',
        ]);

        return [$advertiser, $publisher, $site, $order->fresh(), $item->fresh()];
    }

    public function test_publisher_live_url_then_advertiser_approve_succeeds(): void
    {
        [$advertiser, $publisher, $site, $order, $item] = $this->paidProcessingOrder();

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://approve-after-live.example/published-post',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $item->refresh();
        $this->assertSame('review', $order->status);
        $this->assertSame('https://approve-after-live.example/published-post', $item->live_url);
        $this->assertNotNull($item->live_url_submitted_at);

        $expectedPayout = $item->publisherPayoutAmount();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->completed_at);

        $publisherWallet = Wallet::where('user_id', $publisher->id)->first();
        $this->assertEqualsWithDelta($expectedPayout, (float) $publisherWallet->balance, 0.01);

        $advertiserWallet = Wallet::where('user_id', $advertiser->id)->first();
        $this->assertEqualsWithDelta(0.0, (float) $advertiserWallet->reserved_balance, 0.01);

        if (Schema::hasColumn('sites', 'completed_orders_count')) {
            $this->assertSame(1, (int) $site->fresh()->completed_orders_count);
        }
    }

    public function test_approve_succeeds_when_sites_completed_orders_count_column_is_missing(): void
    {
        [$advertiser, $publisher, , $order, $item] = $this->paidProcessingOrder();

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://approve-after-live.example/drift-safe',
            ])
            ->assertOk();

        $this->assertSame('review', $order->fresh()->status);

        // Stub the healer so it cannot re-add the column mid-request — we want the
        // refreshCompletedOrdersCount guard path, not silent schema repair.
        $this->mock(CheckoutSchemaService::class, function ($mock) {
            $mock->shouldReceive('ensureCheckoutTables')->andReturnNull();
            $mock->shouldReceive('filterExistingColumns')
                ->andReturnUsing(function (string $table, array $payload) {
                    foreach (array_keys($payload) as $column) {
                        if (! Schema::hasColumn($table, $column)) {
                            unset($payload[$column]);
                        }
                    }

                    return $payload;
                });
        });

        Schema::table('sites', function ($table) {
            $table->dropColumn('completed_orders_count');
        });
        $this->assertFalse(Site::hasSitesColumn('completed_orders_count'));

        try {
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.orders.approve', $order->id))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('completed', $order->fresh()->status);
            $this->assertGreaterThan(0.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
        } finally {
            if (! Schema::hasColumn('sites', 'completed_orders_count')) {
                Schema::table('sites', function ($table) {
                    $table->unsignedInteger('completed_orders_count')->default(0);
                });
            }
        }
    }

    public function test_approve_is_blocked_when_payment_is_not_paid(): void
    {
        [$advertiser, $publisher, , $order, $item] = $this->paidProcessingOrder();

        $order->update([
            'status' => 'review',
            'payment_status' => 'failed',
            'paid_at' => null,
        ]);
        $item->update([
            'live_url' => 'https://approve-after-live.example/unpaid-review',
            'live_url_submitted_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'message' => 'This order cannot be approved because payment is not complete.',
            ]);

        $this->assertSame('review', $order->fresh()->status);
        $this->assertEqualsWithDelta(
            0.0,
            (float) Wallet::where('user_id', $publisher->id)->value('balance'),
            0.01
        );
    }

    public function test_auto_approve_skips_orders_that_are_not_paid(): void
    {
        [$advertiser, $publisher, , $order, $item] = $this->paidProcessingOrder();

        $order->update([
            'status' => 'review',
            'payment_status' => 'failed',
            'paid_at' => null,
        ]);
        $item->update([
            'live_url' => 'https://approve-after-live.example/unpaid-auto',
            'live_url_submitted_at' => now()->subHours(OrderItem::autoApproveHours() + 2),
            'modification_requested' => 'no',
            'auto_approve_triggered' => false,
        ]);

        $this->artisan('orders:auto-approve')->assertSuccessful();

        $this->assertSame('review', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertFalse((bool) $item->fresh()->auto_approve_triggered);
        $this->assertEqualsWithDelta(
            0.0,
            (float) Wallet::where('user_id', $publisher->id)->value('balance'),
            0.01
        );
        $this->assertEqualsWithDelta(
            115.0,
            (float) Wallet::where('user_id', $advertiser->id)->value('reserved_balance'),
            0.01
        );
    }

    public function test_approve_explains_when_order_is_still_processing(): void
    {
        [$advertiser, , , $order] = $this->paidProcessingOrder();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'message' => 'The publisher has not submitted a live URL yet. Approve becomes available after they submit the live link.',
            ]);

        $this->assertSame('processing', $order->fresh()->status);
    }

    /**
     * Screenshot regression: wallet-paid €34.50 order already in review with live_url
     * (Order History "URL delivered · your review") must Approve without the generic
     * "Failed to approve order. Please try again." Swal.
     */
    public function test_wallet_paid_review_order_with_live_url_approves_like_order_393(): void
    {
        $price = 34.50;
        [$advertiser, $publisher, $site, $order] = $this->paidProcessingOrder($price);

        // Already past publisher live-URL submit (matches the advertiser UI state).
        $order->update(['status' => 'review']);
        $item = $order->items()->first();
        $item->update([
            'live_url' => 'https://teqnowebss.com/published-guest-post',
            'live_url_submitted_at' => now()->subHour(),
            'modification_requested' => 'no',
            'publisher_price' => 30.00,
        ]);
        $order->refresh();
        $this->assertSame('review', $order->status);
        $this->assertNotEmpty($item->fresh()->live_url);

        // Exact Hostinger failure mode from production: counter column never migrated.
        $this->mock(CheckoutSchemaService::class, function ($mock) {
            $mock->shouldReceive('ensureCheckoutTables')->andReturnNull();
            $mock->shouldReceive('filterExistingColumns')
                ->andReturnUsing(function (string $table, array $payload) {
                    foreach (array_keys($payload) as $column) {
                        if (! Schema::hasColumn($table, $column)) {
                            unset($payload[$column]);
                        }
                    }

                    return $payload;
                });
        });
        Schema::table('sites', function ($table) {
            $table->dropColumn('completed_orders_count');
        });

        try {
            $response = $this->actingAs($advertiser)
                ->postJson(route('advertiser.orders.approve', $order->id));

            $response->assertOk()
                ->assertJsonPath('success', true);

            $this->assertNotSame(
                'Failed to approve order. Please try again.',
                $response->json('message')
            );
            $this->assertSame('completed', $order->fresh()->status);
            $this->assertEqualsWithDelta(
                30.0,
                (float) Wallet::where('user_id', $publisher->id)->value('balance'),
                0.01
            );
            $this->assertEqualsWithDelta(
                0.0,
                (float) Wallet::where('user_id', $advertiser->id)->value('reserved_balance'),
                0.01
            );
        } finally {
            if (! Schema::hasColumn('sites', 'completed_orders_count')) {
                Schema::table('sites', function ($table) {
                    $table->unsignedInteger('completed_orders_count')->default(0);
                });
            }
        }
    }

    /**
     * Documents why Approve 500'd: unguarded UPDATE of a missing column throws.
     */
    public function test_unguarded_completed_orders_count_update_throws_when_column_missing(): void
    {
        $site = Site::create([
            'publisher_id' => $this->userWithRole('publisher')->id,
            'site_name' => 'Counter Drift Site',
            'site_url' => 'https://counter-drift.example',
            'domain' => 'counter-drift.example',
            'da' => 10, 'dr' => 10, 'traffic' => 100,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 30,
            'publication_time' => '5 days', 'link_type' => 'dofollow',
            'description' => 'x', 'verified' => true, 'active' => true,
            'completed_orders_count' => 0,
        ]);

        Schema::table('sites', function ($table) {
            $table->dropColumn('completed_orders_count');
        });

        try {
            $this->assertFalse(Site::hasSitesColumn('completed_orders_count'));

            try {
                Site::query()->where('id', $site->id)->update(['completed_orders_count' => 1]);
                $this->fail('Expected QueryException when updating missing completed_orders_count');
            } catch (QueryException $e) {
                $this->assertStringContainsString('completed_orders_count', $e->getMessage());
            }

            // Guarded helper must stay silent — this is what Approve relies on.
            Site::refreshCompletedOrdersCount((int) $site->id);
            $this->assertTrue(true);
        } finally {
            if (! Schema::hasColumn('sites', 'completed_orders_count')) {
                Schema::table('sites', function ($table) {
                    $table->unsignedInteger('completed_orders_count')->default(0);
                });
            }
        }
    }

    public function test_approve_includes_debug_detail_when_app_debug_is_on(): void
    {
        config(['app.debug' => true]);
        [$advertiser, , , $order] = $this->paidProcessingOrder();

        // Force a 500 by approving while processing (not the happy path message gate —
        // simulate an unexpected throw via a bogus order id after auth).
        // Instead: break filterExistingColumns to throw inside the transaction.
        $this->mock(CheckoutSchemaService::class, function ($mock) {
            $mock->shouldReceive('ensureCheckoutTables')->andReturnNull();
            $mock->shouldReceive('filterExistingColumns')
                ->andThrow(new \RuntimeException('simulated approve failure for debug payload'));
        });

        $order->update(['status' => 'review']);
        $order->items()->first()->update([
            'live_url' => 'https://approve-after-live.example/debug',
            'live_url_submitted_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'simulated approve failure for debug payload')
            ->assertJsonPath('debug', 'RuntimeException: simulated approve failure for debug payload');
    }

    public function test_recheck_live_url_updates_the_only_placement(): void
    {
        [$advertiser, , , $order, $item] = $this->paidProcessingOrder();
        $item->update([
            'live_url' => 'https://approve-after-live.example/published-post',
            'live_url_submitted_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.recheck-live-url', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live_url_check.ok', true);

        if (Schema::hasColumn('order_items', 'live_url_check_ok')) {
            $this->assertTrue((bool) $item->fresh()->live_url_check_ok);
        }
    }

    public function test_recheck_live_url_requires_a_line_on_multi_item_orders(): void
    {
        [$advertiser, , $site, $order, $first] = $this->paidProcessingOrder();
        $first->update([
            'live_url' => 'https://approve-after-live.example/first',
            'live_url_submitted_at' => now(),
        ]);
        $second = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 80,
            'content_link' => 'https://example.com/second.docx',
            'live_url' => 'https://approve-after-live.example/second',
            'live_url_submitted_at' => now(),
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.recheck-live-url', $order->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.recheck-live-url', $order->id), [
                'order_item_id' => $second->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        if (Schema::hasColumn('order_items', 'live_url_check_ok')) {
            $this->assertTrue((bool) $second->fresh()->live_url_check_ok);
            $this->assertNotTrue((bool) $first->fresh()->live_url_check_ok);
        }
    }
}
