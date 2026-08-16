<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminDisputeMissingSiteTest extends TestCase
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

    public function test_uphold_does_not_refund_when_the_listing_row_is_gone(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Missing Listing',
            'site_url' => 'https://missing-listing.example',
            'domain' => 'missing-listing.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Missing listing clawback fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-MISS-'.random_int(1000, 9999),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'completed_at' => now()->subDays(1),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'publisher_price' => 100,
            'platform_fee_amount' => 15,
            'additional_price' => 0,
            'live_url' => 'https://missing-listing.example/live-post',
        ]);

        $pubWallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 0,
            'currency' => 'EUR',
        ]);
        $advWallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 0,
            'currency' => 'EUR',
        ]);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live URL returns 404 after the listing row disappeared.',
        ]);

        // order_items.site_id is restrict-on-delete, so the listing row cannot
        // be removed in this fixture. Hide it the same way Site::find() would
        // miss a deleted/unresolvable listing in production.
        Site::addGlobalScope('missing-listing', fn (Builder $query) => $query->whereRaw('0 = 1'));

        $this->actingAs($admin)
            ->postJson(route('admin.orders.disputes.uphold', $dispute->id), [
                'admin_notes' => 'Confirmed 404. Listing row is gone so clawback cannot run.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(100.0, (float) $pubWallet->fresh()->balance);
        $this->assertSame(10.0, (float) $advWallet->fresh()->balance);
        $this->assertSame(OrderItemDispute::STATUS_OPEN, $dispute->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }
}
