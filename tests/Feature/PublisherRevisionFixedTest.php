<?php

namespace Tests\Feature;

use App\Mail\LiveUrlSubmitted;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A revision is normally fixed by editing the article in place, so the URL does
 * not change. Making the publisher re-paste it just to clear the request was
 * pointless friction — they can now simply say they are done.
 */
class PublisherRevisionFixedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://revision-fixed.example/*' => Http::response('ok', 200)]);
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Revision Fixed Site',
            'site_url' => 'https://revision-fixed.example',
            'domain' => 'revision-fixed.example',
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

    private function orderInRevision(User $advertiser, Site $site, array $itemExtra = []): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-FIX-'.uniqid(),
            'reference_code' => 'REF-FIX-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
            'live_url' => 'https://revision-fixed.example/live-post',
            'live_url_submitted_at' => now()->subHours(30),
            'modification_requested' => 'yes',
            'modification_requested_at' => now()->subHours(2),
            'completion_notes' => 'Please fix the anchor text.',
            'auto_approve_triggered' => false,
        ], $itemExtra));

        return $order->fresh('items');
    }

    public function test_reporting_a_fix_sends_the_order_back_for_review(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderInRevision($advertiser, $site);
        $item = $order->items->first();

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.revision-fixed', $item->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live_url', 'https://revision-fixed.example/live-post');

        $item->refresh();
        $this->assertSame('no', $item->modification_requested);
        $this->assertNull($item->modification_requested_at);
        $this->assertSame('review', $order->fresh()->status);

        // The URL is untouched — only the review state moved.
        $this->assertSame('https://revision-fixed.example/live-post', $item->live_url);
    }

    public function test_the_review_window_restarts_so_the_advertiser_gets_a_full_look(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderInRevision($advertiser, $site);
        $item = $order->items->first();

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.revision-fixed', $item->id))
            ->assertOk();

        $item->refresh();
        $this->assertTrue($item->live_url_submitted_at->greaterThan(now()->subMinute()));
        $this->assertFalse((bool) $item->auto_approve_triggered);
    }

    public function test_the_advertiser_is_told_so_they_can_approve(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderInRevision($advertiser, $site);
        $item = $order->items->first();

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.revision-fixed', $item->id))
            ->assertOk();

        Mail::assertQueued(LiveUrlSubmitted::class, fn (LiveUrlSubmitted $m) => $m->hasTo($advertiser->email));

        $note = InAppNotification::where('user_id', $advertiser->id)->latest('id')->first();
        $this->assertNotNull($note, 'The advertiser needs a bell telling them to review.');
        $this->assertStringContainsString($order->order_number, $note->title);

        $message = OrderChatMessage::where('order_id', $order->id)
            ->where('sender_type', 'publisher')
            ->latest('id')
            ->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('fixed', $message->message);
    }

    public function test_the_change_request_panel_closes_afterwards(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderInRevision($advertiser, $site);
        $item = $order->items->first();

        $this->actingAs($publisher)
            ->getJson('/chat/messages/'.$order->id)
            ->assertJsonPath('order_details.can_resubmit', true);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.revision-fixed', $item->id))
            ->assertOk();

        $this->actingAs($publisher)
            ->getJson('/chat/messages/'.$order->id)
            ->assertOk()
            ->assertJsonPath('order_details.can_resubmit', false)
            ->assertJsonPath('order_details.modification_requested', 'no');
    }

    public function test_it_is_rejected_when_no_changes_were_requested(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderInRevision($advertiser, $site, ['modification_requested' => 'no']);
        $item = $order->items->first();

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.revision-fixed', $item->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_it_is_rejected_before_any_live_url_exists(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderInRevision($advertiser, $site, ['live_url' => null]);
        $item = $order->items->first();

        // Nothing published means nothing for the advertiser to approve.
        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.revision-fixed', $item->id))
            ->assertStatus(422);

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_another_publisher_cannot_report_the_fix(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderInRevision($advertiser, $site);
        $item = $order->items->first();

        $this->actingAs($this->userWithRole('publisher'))
            ->postJson(route('publisher.orders.revision-fixed', $item->id))
            ->assertForbidden();

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_a_completed_order_cannot_be_reopened_this_way(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderInRevision($advertiser, $site);
        $order->update(['status' => 'completed']);
        $item = $order->items->first();

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.revision-fixed', $item->id))
            ->assertStatus(422);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_the_tasks_page_offers_the_button_alongside_the_url_form(): void
    {
        $publisher = $this->userWithRole('publisher');

        $html = $this->actingAs($publisher)
            ->get(route('publisher.tasks'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('chat-revision-fixed-btn', $html);
        $this->assertStringContainsString('I have fixed it', $html);
        $this->assertStringContainsString('revision-fixed', $html);
        // The URL form stays for the case where the article moved.
        $this->assertStringContainsString('chat-resubmit-form', $html);
        $this->assertStringContainsString('Published at a different URL?', $html);
    }
}
