<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertiserOrdersCompletedViewTest extends TestCase
{
    use RefreshDatabase;

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

    private function siteFor(User $publisher, string $name = 'Completed View Site'): Site
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?: 'completed-view');

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser, ?Site $site, array $orderAttrs = [], array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CV-'.uniqid(),
            'reference_code' => 'REF-CV-'.uniqid(),
            'subtotal' => 103.50,
            'tax' => 0,
            'total_amount' => 103.50,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
            'completed_at' => now(),
        ], $orderAttrs));

        if ($site !== null) {
            OrderItem::create(array_merge([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 103.50,
                'content_link' => 'https://example.com/article.docx',
            ], $itemAttrs));
        }

        return $order->fresh('items');
    }

    public function test_get_order_returns_items_and_flags_for_completed_review_and_empty_items(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $liveUrl = 'https://live.example/completed-guest-post';

        $completed = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-CV-DONE',
            'status' => 'completed',
        ], [
            'live_url' => $liveUrl,
        ]);
        $review = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-CV-REVIEW',
            'status' => 'review',
            'completed_at' => null,
        ], [
            'live_url' => 'https://live.example/review-me',
        ]);
        $empty = $this->makeOrder($advertiser, null, [
            'order_number' => 'ORD-CV-EMPTY',
            'status' => 'completed',
        ]);

        $done = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $completed->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.status', 'completed')
            ->assertJsonPath('order.items_count', 1)
            ->assertJsonPath('order.items.0.live_url', $liveUrl)
            ->assertJsonPath('order.can_approve', false)
            ->assertJsonPath('order.can_request_changes', false)
            ->assertJsonPath('order.needs_content_revision', false)
            ->assertJsonPath('order.can_retry_payment', false)
            ->assertJsonPath('order.chat_readonly', false)
            ->json('order');

        $this->assertSame('Completed', $done['status_label']);
        $this->assertStringContainsString('Your post is live', $done['next_action']);
        $this->assertStringNotContainsString('paid for this placement', $done['next_action']);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $review->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.status', 'review')
            ->assertJsonPath('order.items_count', 1)
            ->assertJsonPath('order.items.0.live_url', 'https://live.example/review-me')
            ->assertJsonPath('order.can_approve', true)
            ->assertJsonPath('order.can_request_changes', true)
            ->assertJsonPath('order.needs_content_revision', false)
            ->assertJsonPath('order.chat_readonly', false);

        $orphan = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $empty->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.status', 'completed')
            ->assertJsonPath('order.items_count', 0)
            ->assertJsonPath('order.can_approve', false)
            ->assertJsonPath('order.can_request_changes', false)
            ->assertJsonPath('order.needs_content_revision', false)
            ->json('order');

        $this->assertSame([], $orphan['items']);
        $this->assertSame('Completed', $orphan['status_label']);
        $this->assertSame('Placement details are missing for this order.', $orphan['next_action']);
        $this->assertStringNotContainsString('No placements', $orphan['next_action']);
        $this->assertStringNotContainsString('paid for this placement', $orphan['next_action']);
    }

    public function test_completed_view_js_contains_live_url_and_honest_empty_state(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/advertiser-orders.js'));

        $this->assertStringContainsString('class="live-url"', $js);
        $this->assertStringContainsString('${escapeHtml(liveUrl)}', $js);
        $this->assertStringContainsString('Your post is live. Open the published URL.', $js);
        $this->assertStringContainsString('Placement details are missing for this order.', $js);
        $this->assertStringContainsString('Placement details', $js);
        $this->assertStringNotContainsString('No placements on this order.', $js);
        $this->assertStringNotContainsString('paid for this placement', $js);
        $this->assertStringNotContainsString("firstItem.site_name : 'N/A'", $js);
    }

    public function test_completed_meta_is_honest_when_items_are_empty(): void
    {
        $advertiser = $this->advertiser();
        $empty = $this->makeOrder($advertiser, null);

        $meta = AdvertiserOrderStatus::meta($empty, $empty->items->first());
        $this->assertSame('Completed', $meta['label']);
        $this->assertSame('Placement details are missing for this order.', $meta['next']);
        $this->assertStringNotContainsString('No placements', $meta['next']);
        $this->assertStringNotContainsString('paid for this placement', $meta['next']);
    }

    public function test_order_timeline_is_200_for_owner_and_403_for_a_stranger(): void
    {
        $advertiser = $this->advertiser();
        $stranger = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
        ], [
            'live_url' => 'https://live.example/timeline-post',
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('notifications.order-timeline', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('order_number', $order->order_number)
            ->assertJsonStructure(['activities']);

        $this->actingAs($stranger)
            ->getJson(route('notifications.order-timeline', $order->id))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthorized');
    }
}
