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
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * Advertiser Content Library + Orders leftovers that used to 500 the page
 * or put a harvestable publisher host in href.
 */
class AdvertiserLibraryOrdersLeftoverTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OrderItemDispute::forgetTableAvailabilityCache();

        parent::tearDown();
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, string $domain = 'library-orders.example'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Leftover '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
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

    private function paidOrder(User $advertiser, Site $site, array $itemAttrs = []): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LO-'.uniqid(),
            'reference_code' => 'REF-LO-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now(),
        ]);

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
        ], $itemAttrs));

        return $order->fresh('items');
    }

    public function test_library_still_loads_when_disputes_table_is_missing(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $submission->update(['title' => 'Disputes Missing Article']);
        $order = $this->paidOrder($advertiser, $site, [
            'content_submission_id' => $submission->id,
            'live_url' => 'https://live.example/post',
            'live_url_submitted_at' => now(),
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
        ]);

        Schema::drop('order_item_disputes');
        OrderItemDispute::forgetTableAvailabilityCache();

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'completed']))
            ->assertOk()
            ->assertSee('Disputes Missing Article')
            ->assertSee('https://live.example/post');
    }

    public function test_library_strips_leftover_javascript_live_url_from_href(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'js-live.example');
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $submission->update(['title' => 'Unsafe Live URL Article']);
        $order = $this->paidOrder($advertiser, $site, [
            'content_submission_id' => $submission->id,
            'live_url' => 'javascript:alert(1)',
            'live_url_submitted_at' => now(),
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'completed']))
            ->assertOk()
            ->assertSee('Unsafe Live URL Article')
            ->getContent();

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString("href='javascript:", $html);
        $this->assertStringNotContainsString('data-copy-url="javascript:', $html);
    }

    public function test_orders_list_and_details_use_first_party_visit_url(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'orders-visit.example');
        $order = $this->paidOrder($advertiser, $site);
        $visit = route('advertiser.catalog.visit', $site->id, false);

        $list = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('orders.0.items.0.site_url', $site->site_url)
            ->assertJsonPath('orders.0.items.0.visit_url', $visit)
            ->json();

        $this->assertSame($order->id, $list['orders'][0]['id']);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.items.0.site_url', $site->site_url)
            ->assertJsonPath('order.items.0.visit_url', $visit);
    }

    public function test_order_chat_details_include_visit_url(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'chat-visit.example');
        $order = $this->paidOrder($advertiser, $site);
        $order->update(['status' => 'processing']);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Working on it.',
            'is_read' => false,
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_details.website_url', $site->site_url)
            ->assertJsonPath('order_details.visit_url', route('advertiser.catalog.visit', $site->id, false));
    }

    public function test_library_list_survives_missing_archived_at_column(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'No Archive Column']);

        Schema::table('content_submissions', function ($table) {
            $table->dropIndex('content_submissions_archived_at_index');
            $table->dropColumn('archived_at');
        });

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('No Archive Column');
    }
}
