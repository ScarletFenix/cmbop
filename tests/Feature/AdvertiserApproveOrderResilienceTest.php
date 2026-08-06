<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutSchemaService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Advertiser Approve must succeed when money moves, even if optional side
 * tables (ledger / order timeline) are missing on an older deploy.
 */
class AdvertiserApproveOrderResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $roleModel->id]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    /** @return array{0: User, 1: User, 2: Order} */
    private function orderInReview(): array
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        Wallet::create(['user_id' => $advertiser->id, 'role_id' => $advertiser->active_role_id, 'balance' => 0, 'reserved_balance' => 120]);
        Wallet::create(['user_id' => $publisher->id, 'role_id' => $publisher->active_role_id, 'balance' => 0, 'reserved_balance' => 0]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Approve Resilience Site',
            'site_url' => 'https://approve-resilience.example',
            'domain' => 'approve-resilience.example',
            'da' => 30, 'dr' => 35, 'traffic' => 4000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 120,
            'turnaround_time' => '3days',
            'publication_time' => '5 days', 'link_type' => 'dofollow',
            'description' => 'Test site', 'verified' => true, 'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-APR-'.uniqid(),
            'reference_code' => 'REF-APR-'.uniqid(),
            'subtotal' => 120, 'tax' => 0, 'total_amount' => 120,
            'payment_method' => 'wallet', 'payment_status' => 'paid',
            'status' => 'review', 'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 120, 'publisher_price' => 100,
            'content_link' => 'https://example.com/article.docx',
            'accepted_at' => now()->subDay(),
            'live_url' => 'https://approve-resilience.example/post',
            'live_url_submitted_at' => now()->subHours(2),
            'modification_requested' => 'no',
        ]);

        return [$advertiser, $publisher, $order->fresh('items')];
    }

    public function test_approve_succeeds_when_order_activities_table_is_missing(): void
    {
        [$advertiser, $publisher, $order] = $this->orderInReview();

        Schema::rename('order_activities', 'order_activities_bak_test');

        try {
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.orders.approve', $order->id))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('completed', $order->fresh()->status);
            $this->assertEquals(100.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
        } finally {
            Schema::rename('order_activities_bak_test', 'order_activities');
        }
    }

    public function test_approve_succeeds_when_wallet_ledger_table_is_missing(): void
    {
        [$advertiser, $publisher, $order] = $this->orderInReview();

        Schema::rename('wallet_transactions', 'wallet_transactions_bak_test');

        try {
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.orders.approve', $order->id))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('completed', $order->fresh()->status);
            $this->assertEquals(100.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
        } finally {
            Schema::rename('wallet_transactions_bak_test', 'wallet_transactions');
        }
    }

    public function test_approve_succeeds_when_optional_order_item_columns_are_missing(): void
    {
        [$advertiser, $publisher, $order] = $this->orderInReview();

        // Simulate Hostinger schema drift: optional workflow columns never migrated.
        // Drop after ensureCheckoutTables would re-add them — stub the healer instead.
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

        Schema::table('order_items', function ($table) {
            if (Schema::hasColumn('order_items', 'publisher_status')) {
                $table->dropColumn('publisher_status');
            }
            if (Schema::hasColumn('order_items', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });

        try {
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.orders.approve', $order->id))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('completed', $order->fresh()->status);
            $this->assertEquals(100.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
        } finally {
            // Restore columns for later tests in the same process when possible.
            if (! Schema::hasColumn('order_items', 'publisher_status')) {
                Schema::table('order_items', function ($table) {
                    $table->string('publisher_status', 40)->nullable();
                });
            }
            if (! Schema::hasColumn('order_items', 'completed_at')) {
                Schema::table('order_items', function ($table) {
                    $table->timestamp('completed_at')->nullable();
                });
            }
        }
    }

    public function test_approve_succeeds_when_ledger_write_throws(): void
    {
        [$advertiser, $publisher, $order] = $this->orderInReview();

        $this->mock(WalletLedgerService::class, function ($mock) {
            $mock->shouldReceive('recordTransferIn')
                ->once()
                ->andThrow(new \RuntimeException('ledger schema mismatch'));
        });

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertEquals(100.0, (float) Wallet::where('user_id', $publisher->id)->value('balance'));
    }
}
