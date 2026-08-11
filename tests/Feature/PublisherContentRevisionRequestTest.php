<?php

namespace Tests\Feature;

use App\Mail\ContentRevisionFulfilled;
use App\Mail\ContentRevisionRequested;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Support\EmailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublisherContentRevisionRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $advertiser;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

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
            'site_name' => 'Revision Site',
            'site_url' => 'https://revision.example',
            'domain' => 'revision.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 1200,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Content revision test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeProcessingItem(): OrderItem
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/old-article',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
        ]);
    }

    public function test_publisher_can_request_content_revision_after_accept(): void
    {
        $item = $this->makeProcessingItem();
        $reason = 'Please fix the brand spelling and shorten the intro paragraph.';

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.request-content-revision', $item->id), [
                'reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertTrue($item->isContentRevisionRequested());
        $this->assertSame($reason, $item->content_revision_reason);

        Mail::assertQueued(ContentRevisionRequested::class);
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $this->advertiser->id)
                ->where('type', 'content_revision_requested')
                ->exists()
        );
        $this->assertTrue(
            OrderChatMessage::query()
                ->where('order_id', $item->order_id)
                ->where('sender_type', 'publisher')
                ->where('message', 'like', 'Revised article requested:%')
                ->exists()
        );
    }

    public function test_publisher_cannot_request_content_revision_before_accept(): void
    {
        $item = $this->makeProcessingItem();
        $item->order->update(['status' => 'pending']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.request-content-revision', $item->id), [
                'reason' => 'Please send a cleaner draft with correct links.',
            ])
            ->assertStatus(422);

        $this->assertFalse($item->fresh()->isContentRevisionRequested());
    }

    public function test_live_url_submit_blocked_while_content_revision_open(): void
    {
        $item = $this->makeProcessingItem();
        $item->update([
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Need a shorter draft for our guidelines.',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://revision.example/post',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_advertiser_fulfills_content_revision_with_link(): void
    {
        $item = $this->makeProcessingItem();
        $item->update([
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please send a cleaner draft with correct links.',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'content_link' => 'https://docs.example/new-article',
                'note' => 'Updated intro and brand mentions.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertFalse($item->isContentRevisionRequested());
        $this->assertSame('https://docs.example/new-article', $item->content_link);
        $this->assertNotNull($item->content_revision_resolved_at);

        Mail::assertQueued(ContentRevisionFulfilled::class);
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('type', 'content_revision_fulfilled')
                ->exists()
        );
    }

    public function test_publisher_can_cancel_after_accept_and_refunds_advertiser(): void
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $this->advertiser->id, 'role_id' => Wallet::advertiserRoleId()],
            ['balance' => 0, 'reserved_balance' => 0, 'currency' => 'EUR']
        );
        $wallet->addBalance(200);
        $wallet->refresh()->reserveForOrder(80);

        $item = $this->makeProcessingItem();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'We cannot publish this niche after editorial review.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order = $item->order->fresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('refunded', $order->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(200.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
    }

    public function test_email_catalog_includes_content_revision_mailables(): void
    {
        $types = EmailCatalog::all();
        $this->assertArrayHasKey('content_revision_requested', $types);
        $this->assertArrayHasKey('content_revision_fulfilled', $types);

        $requested = EmailCatalog::makeMailable('content_revision_requested');
        $fulfilled = EmailCatalog::makeMailable('content_revision_fulfilled');
        $this->assertNotNull($requested);
        $this->assertNotNull($fulfilled);
        $this->assertStringContainsString('revised article', strtolower($requested->render()));
        $this->assertStringContainsString('revised article', strtolower($fulfilled->render()));
    }

    public function test_publisher_tasks_page_exposes_content_revision_and_cancel_hooks(): void
    {
        $page = $this->actingAs($this->publisher)->get(route('publisher.tasks'));
        $page->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('request-content-revision', $html);
        $this->assertStringContainsString('contentRevisionModal', $html);
        $this->assertStringContainsString('Cancel order', $html);
    }
}
