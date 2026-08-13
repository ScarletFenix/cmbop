<?php

namespace Tests\Feature;

use App\Mail\OrderAccepted;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Services\LiveUrlHealthChecker;
use App\Support\SocialPostUrlValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HomepageSocialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $advertiser;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->mock(LiveUrlHealthChecker::class, function ($mock) {
            $mock->shouldReceive('check')->andReturn([
                'ok' => true,
                'status' => 200,
                'message' => 'Reachable',
                'checked_at' => now(),
            ]);
        });

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Delivery Site',
            'site_url' => 'https://delivery.example',
            'domain' => 'delivery.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Delivery test site',
            'verified' => true,
            'active' => 1,
        ]);
    }

    private function makeProcessingItem(array $itemAttrs = []): OrderItem
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF'.random_int(1000, 9999),
            'subtotal' => 138,
            'tax' => 0,
            'total_amount' => 138,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 138,
            'additional_price' => 0,
            'homepage_days' => 7,
            'homepage_price' => 25,
            'social_channels' => ['facebook', 'x'],
            'publisher_price' => 100,
            'publisher_status' => 'accepted',
            'accepted_at' => now(),
        ], $itemAttrs));
    }

    public function test_live_url_alone_is_enough_when_social_offered(): void
    {
        $item = $this->makeProcessingItem();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://delivery.example/article',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertSame('https://delivery.example/article', $item->live_url);
        $this->assertNull($item->social_post_urls);
        $this->assertSame('review', $item->order->fresh()->status);
    }

    public function test_optional_social_post_urls_are_persisted(): void
    {
        $item = $this->makeProcessingItem();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://delivery.example/article',
                'social_post_urls' => [
                    'facebook' => 'https://www.facebook.com/posts/123',
                    'x' => 'https://x.com/user/status/456',
                    'instagram' => 'https://instagram.com/p/ignored', // not offered
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertSame([
            'facebook' => 'https://www.facebook.com/posts/123',
            'x' => 'https://x.com/user/status/456',
        ], $item->social_post_urls);
    }

    public function test_soft_host_mismatch_still_saves_with_warning(): void
    {
        $item = $this->makeProcessingItem();

        $response = $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://delivery.example/article',
                'social_post_urls' => [
                    'facebook' => 'https://example.com/not-facebook',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($response->json('social_warnings'));
        $item->refresh();
        $this->assertSame('https://example.com/not-facebook', $item->social_post_urls['facebook']);
    }

    public function test_publisher_order_payload_includes_homepage_and_social(): void
    {
        $item = $this->makeProcessingItem([
            'social_post_urls' => ['facebook' => 'https://facebook.com/posts/1'],
        ]);

        $payload = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.details', $item->id))
            ->assertOk()
            ->json('data');

        $this->assertSame(7, $payload['homepage_days']);
        $this->assertEquals(25.0, (float) $payload['homepage_price']);
        $this->assertSame(['facebook', 'x'], $payload['social_channels']);
        $this->assertSame(['facebook' => 'https://facebook.com/posts/1'], $payload['social_post_urls']);
    }

    public function test_tasks_ui_includes_social_post_url_fields(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.tasks'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('completeSocialFields', $html);
        $this->assertStringContainsString('socialPostsModal', $html);
        $this->assertStringContainsString('update-social-posts', $html);
        $this->assertStringContainsString('social-post-url', $html);
        $this->assertStringContainsString('collectSocialPostUrls', $html);
        $this->assertStringContainsString('/social-posts', $html);
    }

    public function test_social_posts_can_be_added_after_live_url(): void
    {
        $item = $this->makeProcessingItem([
            'live_url' => 'https://delivery.example/article',
            'live_url_submitted_at' => now(),
        ]);
        $item->order->update(['status' => 'review']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.social-posts', $item->id), [
                'social_post_urls' => [
                    'facebook' => 'https://www.facebook.com/posts/999',
                    'x' => 'https://x.com/user/status/999',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('social_post_urls.facebook', 'https://www.facebook.com/posts/999');

        $item->refresh();
        $this->assertSame([
            'facebook' => 'https://www.facebook.com/posts/999',
            'x' => 'https://x.com/user/status/999',
        ], $item->social_post_urls);
        $this->assertSame('https://delivery.example/article', $item->live_url);
    }

    public function test_social_posts_endpoint_requires_live_url_first(): void
    {
        $item = $this->makeProcessingItem();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.social-posts', $item->id), [
                'social_post_urls' => [
                    'facebook' => 'https://www.facebook.com/posts/1',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull($item->fresh()->social_post_urls);
    }

    public function test_social_posts_endpoint_rejects_when_social_not_offered(): void
    {
        $item = $this->makeProcessingItem([
            'live_url' => 'https://delivery.example/article',
            'live_url_submitted_at' => now(),
            'social_channels' => [],
        ]);
        $item->order->update(['status' => 'review']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.social-posts', $item->id), [
                'social_post_urls' => [
                    'facebook' => 'https://www.facebook.com/posts/1',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_social_post_url_validator_unit_behaviour(): void
    {
        $validator = new SocialPostUrlValidator;
        $result = $validator->normalize(['facebook'], [
            'facebook' => 'https://m.facebook.com/story.php?id=1',
            'x' => 'https://x.com/should-drop',
        ]);

        $this->assertSame(['facebook' => 'https://m.facebook.com/story.php?id=1'], $result['urls']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_order_item_price_breakdown_includes_homepage(): void
    {
        $item = $this->makeProcessingItem();
        $breakdown = $item->price_breakdown;

        $this->assertEquals(113.0, (float) $breakdown['base_price']);
        $this->assertEquals(25.0, (float) $breakdown['homepage_price']);
        $this->assertSame(7, $breakdown['homepage_days']);
        $this->assertEquals(138.0, (float) $breakdown['total_price']);
    }

    public function test_tasks_list_payload_includes_social_channels_for_submit_button(): void
    {
        $item = $this->makeProcessingItem();

        $payload = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data'))
            ->assertOk()
            ->json();

        $match = collect($payload['data'] ?? [])->firstWhere('id', $item->id);

        $this->assertNotNull($match, 'Tasks list should include the processing item');
        $this->assertSame(['facebook', 'x'], $match['social_channels']);
        $this->assertSame([], $match['social_post_urls'] ?? []);
        $this->assertSame(7, $match['homepage_days']);
    }

    public function test_order_accepted_email_and_bell_list_homepage_and_social(): void
    {
        $item = $this->makeProcessingItem([
            'publisher_status' => null,
            'accepted_at' => null,
        ]);
        $item->order->update(['status' => 'pending']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertQueued(OrderAccepted::class);

        $html = (new OrderAccepted($item->order->fresh(), $item->fresh(), $this->site))->render();
        $this->assertStringContainsString('Homepage placement', $html);
        $this->assertStringContainsString('7 day', $html);
        $this->assertStringContainsString('Social promotion', $html);
        $this->assertStringContainsString('Facebook', $html);
        $this->assertStringContainsString('(included)', $html);
        $this->assertStringNotContainsString('facebook.com/posts', $html);

        $note = InAppNotification::query()
            ->where('user_id', $this->advertiser->id)
            ->where('type', InAppNotificationService::TYPE_ORDER_ACCEPTED)
            ->latest('id')
            ->first();

        $this->assertNotNull($note);
        $this->assertStringContainsString('homepage (7 days', (string) $note->message);
        $this->assertStringContainsString('social (Facebook, X)', (string) $note->message);
    }

    public function test_order_accepted_email_omits_homepage_social_when_not_purchased(): void
    {
        $item = $this->makeProcessingItem([
            'homepage_days' => null,
            'homepage_price' => 0,
            'social_channels' => [],
            'publisher_status' => null,
            'accepted_at' => null,
        ]);
        $item->order->update(['status' => 'pending']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertOk();

        Mail::assertQueued(OrderAccepted::class);

        $html = (new OrderAccepted($item->order->fresh(), $item->fresh(), $this->site))->render();
        $this->assertStringNotContainsString('Homepage placement', $html);
        $this->assertStringNotContainsString('Social promotion', $html);
    }

    public function test_tasks_page_falls_back_to_row_payload_for_social_channels(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.tasks'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function parseSocialChannelsAttr', $html);
        $this->assertStringContainsString('window._publisherTaskItems', $html);
        $this->assertStringContainsString('item.social_channels', $html);
        $this->assertStringContainsString('Live URL alone is enough', $html);
    }
}
