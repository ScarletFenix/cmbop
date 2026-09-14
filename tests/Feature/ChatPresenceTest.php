<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChatPresenceTest extends TestCase
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

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Presence Site',
            'site_url' => 'https://presence.example',
            'domain' => 'presence.example',
            'da' => 40,
            'dr' => 40,
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

    private function orderFor(User $advertiser, Site $site): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-PRES-'.uniqid(),
            'reference_code' => 'REF-PRES-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
            'modification_requested' => 'no',
        ]);

        return $order->fresh('items');
    }

    public function test_online_window_and_last_seen_labels(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $fresh = User::factory()->make(['last_seen_at' => now()->subSeconds(30)]);
        $this->assertTrue($fresh->isOnline());
        $this->assertSame('Online', $fresh->lastSeenLabel());
        $this->assertTrue($fresh->presencePayload()['online']);

        $minutes = User::factory()->make(['last_seen_at' => now()->subMinutes(10)]);
        $this->assertFalse($minutes->isOnline());
        $this->assertSame('Last seen 10m ago', $minutes->lastSeenLabel());

        $hours = User::factory()->make(['last_seen_at' => now()->subHours(3)]);
        $this->assertSame('Last seen 3h ago', $hours->lastSeenLabel());

        $yesterday = User::factory()->make(['last_seen_at' => now()->subDay()]);
        $this->assertSame('Last seen yesterday', $yesterday->lastSeenLabel());

        $never = User::factory()->make(['last_seen_at' => null]);
        $this->assertFalse($never->isOnline());
        $this->assertNull($never->lastSeenLabel());
        $this->assertNull($never->presencePayload()['label']);

        $hidden = User::factory()->make(['last_seen_at' => now()]);
        $this->assertArrayNotHasKey('last_seen_at', $hidden->toArray());
        $this->assertNotNull($hidden->presencePayload()['last_seen_at']);

        Carbon::setTestNow();
    }

    public function test_chat_get_stamps_viewer_last_seen_and_throttles(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $order = $this->orderFor($advertiser, $this->siteFor($publisher));
        $updatedAt = $advertiser->fresh()->updated_at;

        $this->assertNull($advertiser->last_seen_at);

        Carbon::setTestNow('2026-09-14 12:00:05');

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk();

        $first = $advertiser->fresh()->last_seen_at;
        $this->assertNotNull($first);
        $this->assertTrue($first->equalTo(now()));
        $this->assertTrue($advertiser->fresh()->updated_at->equalTo($updatedAt));
        $this->assertNull($publisher->fresh()->last_seen_at);

        Carbon::setTestNow(now()->addSeconds(20));
        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk();
        $this->assertTrue($advertiser->fresh()->last_seen_at->equalTo($first));

        Carbon::setTestNow(now()->addSeconds(50));
        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk();
        $this->assertTrue($advertiser->fresh()->last_seen_at->equalTo(now()));
        $this->assertTrue($advertiser->fresh()->updated_at->equalTo($updatedAt));

        Carbon::setTestNow();
    }

    public function test_advertiser_sees_publisher_presence(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $publisher->forceFill(['last_seen_at' => now()->subSeconds(20)])->save();
        $order = $this->orderFor($advertiser, $this->siteFor($publisher));

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('order_details.counterpart.name', $publisher->name)
            ->assertJsonPath('order_details.counterpart.role', 'publisher')
            ->assertJsonPath('order_details.counterpart.online', true)
            ->assertJsonPath('order_details.counterpart.label', 'Online');

        Carbon::setTestNow();
    }

    public function test_publisher_sees_advertiser_presence(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $advertiser = $this->advertiser();
        $advertiser->forceFill(['last_seen_at' => now()->subHours(2)])->save();
        $publisher = $this->publisher();
        $order = $this->orderFor($advertiser, $this->siteFor($publisher));

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('order_details.counterpart.name', $advertiser->name)
            ->assertJsonPath('order_details.counterpart.role', 'advertiser')
            ->assertJsonPath('order_details.counterpart.online', false)
            ->assertJsonPath('order_details.counterpart.label', 'Last seen 2h ago');

        Carbon::setTestNow();
    }

    public function test_stale_and_missing_last_seen_are_not_online(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $publisher->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        $order = $this->orderFor($advertiser, $this->siteFor($publisher));

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('order_details.counterpart.online', false)
            ->assertJsonPath('order_details.counterpart.label', 'Last seen 10m ago');

        $quiet = $this->publisher();
        $quietOrder = $this->orderFor($advertiser, $this->siteFor($quiet));

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $quietOrder->id))
            ->assertOk()
            ->assertJsonPath('order_details.counterpart.online', false)
            ->assertJsonPath('order_details.counterpart.label', null);

        Carbon::setTestNow();
    }

    public function test_self_purchase_does_not_show_own_presence(): void
    {
        $buyer = $this->advertiser();
        $order = $this->orderFor($buyer, $this->siteFor($buyer));

        $this->actingAs($buyer)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('order_details.counterpart', null);
    }

    public function test_unauthorized_chat_does_not_leak_presence(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $outsider = $this->advertiser();
        $order = $this->orderFor($advertiser, $this->siteFor($publisher));

        $this->actingAs($outsider)
            ->getJson(route('chat.messages', $order->id))
            ->assertStatus(403)
            ->assertJsonMissingPath('order_details.counterpart');
    }

    public function test_chat_ui_wires_presence_hook(): void
    {
        $html = (string) file_get_contents(resource_path('views/partials/order-chat-modal.blade.php'));
        $js = (string) file_get_contents(public_path('js/order-chat.js'));
        $css = (string) file_get_contents(public_path('assets/css/chat.css'));

        $this->assertStringContainsString('id="chatPresence"', $html);
        $this->assertStringContainsString('chat-presence__text', $html);
        $this->assertStringContainsString('OrderChat.prototype.renderPresence', $js);
        $this->assertStringContainsString('order_details.counterpart', $js);
        $this->assertStringContainsString('.chat-presence.is-online', $css);
        $this->assertStringContainsString('pointer-events: none', $css);
        $this->assertStringContainsString('flex: 1', $css);
    }
}
