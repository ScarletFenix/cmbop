<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Disputes shipped after the rest of the order system, so a deploy whose
 * migrations have not run yet still has an orders table and no disputes table.
 * Orders are the core of the product; they must not 500 because an optional
 * feature's schema is missing.
 */
class OrdersSurviveMissingDisputeTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        OrderItemDispute::forgetTableAvailabilityCache();
    }

    protected function tearDown(): void
    {
        OrderItemDispute::forgetTableAvailabilityCache();
        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $roleModel->id]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    private function order(User $advertiser, User $publisher): Order
    {
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Dispute Schema Site',
            'site_url' => 'https://dispute-schema.example',
            'domain' => 'dispute-schema.example',
            'da' => 30, 'dr' => 30, 'traffic' => 1000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 90,
            'publication_time' => '5 days', 'link_type' => 'dofollow',
            'description' => 'Test site', 'verified' => true, 'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-DSP-'.uniqid(),
            'reference_code' => 'REF-DSP-'.uniqid(),
            'subtotal' => 90, 'tax' => 0, 'total_amount' => 90,
            'payment_method' => 'wallet', 'payment_status' => 'paid',
            'status' => 'processing', 'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 90, 'publisher_price' => 75,
            'content_link' => 'https://example.com/article.docx',
        ]);

        return $order->fresh('items');
    }

    private function dropDisputesTable(): void
    {
        Schema::dropIfExists('order_item_disputes');
        OrderItemDispute::forgetTableAvailabilityCache();
    }

    public function test_admin_can_open_an_order_without_the_disputes_table(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->order($this->userWithRole('advertiser'), $this->userWithRole('publisher'));

        $this->dropDisputesTable();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk();
    }

    public function test_advertiser_orders_list_survives_without_the_disputes_table(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->order($advertiser, $this->userWithRole('publisher'));

        $this->dropDisputesTable();

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_order_page_still_shows_disputes_when_the_table_is_there(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->order($advertiser, $this->userWithRole('publisher'));

        OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => 'open',
            'reason' => 'The link was removed from the article after publication.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('removed from the article', false);
    }
}
