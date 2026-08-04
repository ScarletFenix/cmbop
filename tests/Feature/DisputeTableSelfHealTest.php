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
 * A missing order_item_disputes table is not a feature switched off, it is a
 * feature that silently vanished: advertisers cannot raise a dispute and admins
 * cannot resolve one, with nothing on screen to explain why. Guarding the reads
 * stops the crash; creating the table is what gives the feature back.
 */
class DisputeTableSelfHealTest extends TestCase
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

    private function order(): Order
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Heal Site',
            'site_url' => 'https://heal.example',
            'domain' => 'heal.example',
            'da' => 30, 'dr' => 30, 'traffic' => 1000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 90,
            'publication_time' => '5 days', 'link_type' => 'dofollow',
            'description' => 'Test site', 'verified' => true, 'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-HEAL-'.uniqid(),
            'reference_code' => 'REF-HEAL-'.uniqid(),
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

    public function test_opening_an_order_restores_the_missing_table(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->order();

        Schema::dropIfExists('order_item_disputes');
        OrderItemDispute::forgetTableAvailabilityCache();
        $this->assertFalse(Schema::hasTable('order_item_disputes'));

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk();

        $this->assertTrue(
            Schema::hasTable('order_item_disputes'),
            'The order screen did not restore the table it needs.'
        );
    }

    public function test_the_restored_table_actually_takes_a_dispute(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->order();

        Schema::dropIfExists('order_item_disputes');
        OrderItemDispute::forgetTableAvailabilityCache();

        $this->actingAs($admin)->get(route('admin.orders.show', $order->id))->assertOk();

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $order->user_id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'The link was removed from the article a week after publication.',
        ]);

        $this->assertTrue($dispute->exists);
        $this->assertDatabaseHas('order_item_disputes', ['id' => $dispute->id]);
    }

    public function test_healing_leaves_existing_disputes_alone(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->order();

        $existing = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $order->user_id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'An existing dispute that must survive the check.',
        ]);

        OrderItemDispute::forgetTableAvailabilityCache();
        OrderItemDispute::ensureTable();

        $this->actingAs($admin)->get(route('admin.orders.show', $order->id))->assertOk();

        $this->assertDatabaseHas('order_item_disputes', ['id' => $existing->id]);
    }
}
