<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Deployments frequently ship code before running migrations, which used to turn
 * the whole advertiser Orders tab into "Failed to fetch orders" and, because the
 * chat launcher lives inside an order row, made chat look broken too.
 */
class OrdersBehindMigrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OrderItemDispute::forgetTableAvailabilityCache();

        parent::tearDown();
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Behind Migrations Site',
            'site_url' => 'https://behind-migrations.example',
            'domain' => 'behind-migrations.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 50,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function orderFor(User $advertiser): Order
    {
        $publisher = User::factory()->create();
        $site = $this->siteFor($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-BM-1',
            'reference_code' => 'REF-BM-1',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'content_link' => 'https://example.com/doc',
            'live_url' => 'https://behind-migrations.example/post',
        ]);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Your post is live.',
            'is_read' => false,
        ]);

        return $order;
    }

    private function dropDisputesTable(): void
    {
        Schema::drop('order_item_disputes');
        OrderItemDispute::forgetTableAvailabilityCache();
    }

    public function test_orders_list_still_loads_when_disputes_table_is_missing(): void
    {
        $advertiser = $this->advertiser();
        $order = $this->orderFor($advertiser);
        $this->dropDisputesTable();

        $response = $this->actingAs($advertiser)->getJson(route('advertiser.orders.list'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('orders.0.order_number', $order->order_number)
            ->assertJsonPath('orders.0.can_report_link_removed', false)
            ->assertJsonPath('orders.0.dispute_status', null);
    }

    public function test_order_details_still_load_when_disputes_table_is_missing(): void
    {
        $advertiser = $this->advertiser();
        $order = $this->orderFor($advertiser);
        $this->dropDisputesTable();

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.order_number', $order->order_number);
    }

    public function test_unread_chat_count_survives_a_missing_disputes_table(): void
    {
        $advertiser = $this->advertiser();
        $this->orderFor($advertiser);
        $this->dropDisputesTable();

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonPath('orders.0.unread_chat', 1);
    }

    public function test_advertiser_can_read_order_chat_when_disputes_table_is_missing(): void
    {
        $advertiser = $this->advertiser();
        $order = $this->orderFor($advertiser);
        $this->dropDisputesTable();

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('messages.0.message', 'Your post is live.');
    }

    public function test_dispute_metadata_is_still_reported_when_the_table_exists(): void
    {
        $advertiser = $this->advertiser();
        $order = $this->orderFor($advertiser);
        $order->update(['status' => 'completed', 'completed_at' => now()]);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items()->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'The link was removed from the article.',
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonPath('orders.0.dispute_id', $dispute->id)
            ->assertJsonPath('orders.0.dispute_status', OrderItemDispute::STATUS_OPEN);
    }

    public function test_orders_list_reports_a_real_failure_with_an_error_status(): void
    {
        $advertiser = $this->advertiser();
        $this->orderFor($advertiser);

        // A broken orders table is an infrastructure fault: the client needs a
        // non-2xx so it renders the retry affordance instead of "no orders yet".
        Schema::drop('order_items');

        $response = $this->actingAs($advertiser)->getJson(route('advertiser.orders.list'));

        $response->assertStatus(500)->assertJsonPath('success', false);
        $this->assertStringNotContainsString('SQLSTATE', $response->json('message'));
    }
}
