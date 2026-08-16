<?php

namespace Tests\Feature;

use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherPublishNudge;
use App\Models\Blog;
use App\Models\CheckoutIntent;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use App\Services\ContentUpload\ScheduledOrderService;
use App\Services\Reminders\StalledOrderQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Hostinger leftovers store non-datetime strings in timestamp columns.
 * PHP casts them to null; SQL must not treat them as accepted, due, live, or paid.
 */
class LeftoverOrderItemDatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'orders.auto_approve_hours' => 1,
            'orders.auto_approve_require_live_url_ok' => false,
            'orders.auto_approve_reminder_hours_before' => 0,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, string $domain = 'leftover-dates.example'): Site
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
            'category' => 'Technology',
            'price' => 80,
            'turnaround_time' => '24h',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Leftover date fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $orderExtra
     * @param  array<string, mixed>  $itemExtra
     */
    private function paidOrder(User $advertiser, Site $site, string $status = 'pending', array $orderExtra = [], array $itemExtra = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LO-'.uniqid(),
            'reference_code' => 'REF-LO-'.uniqid(),
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => $status,
            'paid_at' => now()->subDays(2),
        ], $orderExtra));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 80,
            'publisher_price' => 70,
            'content_link' => 'https://example.com/article.docx',
            'modification_requested' => 'no',
            'content_revision_requested' => 'no',
        ], $itemExtra));

        return $order->fresh('items');
    }

    public function test_admin_order_show_survives_leftover_accepted_and_paid_stamps(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->paidOrder(
            $this->userWithRole('advertiser'),
            $this->siteFor($this->userWithRole('publisher')),
            'processing',
            [],
            ['accepted_at' => now()]
        );

        DB::table('orders')->where('id', $order->id)->update([
            'paid_at' => 'not-a-date',
            'completed_at' => 'not-a-date',
            'scheduled_publish_at' => 'not-a-date',
            'schedule_released_at' => 'not-a-date',
        ]);
        DB::table('order_items')->where('order_id', $order->id)->update([
            'accepted_at' => 'not-a-date',
        ]);

        $order->refresh();
        $this->assertNull($order->paid_at);
        $this->assertNull($order->items->first()->accepted_at);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Not accepted', false);
    }

    public function test_leftover_live_url_submitted_at_is_not_auto_approved(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, 'aa-leftover.example');

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 80,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $order = $this->paidOrder($advertiser, $site, 'review', [], [
            'live_url' => 'https://aa-leftover.example/post',
            'live_url_submitted_at' => now()->subHours(3),
            'live_url_check_ok' => true,
            'auto_approve_triggered' => false,
        ]);
        $item = $order->items->first();

        DB::table('order_items')->where('id', $item->id)->update([
            'live_url_submitted_at' => 'not-a-date',
        ]);

        $item->refresh();
        $this->assertNull($item->live_url_submitted_at);
        $this->assertFalse($item->isReadyForAutoApprove());
        $this->assertFalse(OrderItem::query()->whereLiveUrlSubmittedAtIsRecorded()->whereKey($item->id)->exists());

        Artisan::call('orders:auto-approve');

        $this->assertFalse((bool) $item->fresh()->auto_approve_triggered);
        $this->assertSame('review', $order->fresh()->status);
    }

    public function test_leftover_accepted_at_stays_on_accept_stalled_track(): void
    {
        $publisher = $this->userWithRole('publisher');
        $pending = $this->paidOrder(
            $this->userWithRole('advertiser'),
            $this->siteFor($publisher, 'stalled-accept.example'),
            'pending',
            [],
            [
                'accepted_at' => now()->subDays(5),
                'accept_nudge_stage' => 3,
                'publish_nudge_stage' => 4,
            ]
        );
        $processing = $this->paidOrder(
            $this->userWithRole('advertiser'),
            $this->siteFor($publisher, 'stalled-publish.example'),
            'processing',
            [],
            [
                'accepted_at' => now()->subDays(5),
                'publish_nudge_stage' => 4,
            ]
        );

        DB::table('order_items')->whereIn('id', [
            $pending->items->first()->id,
            $processing->items->first()->id,
        ])->update(['accepted_at' => 'not-a-date']);

        $this->assertSame('accept', $pending->items->first()->fresh()->adminRemindTrack());
        $this->assertTrue(OrderItem::query()->whereAcceptedAtIsMissing()->whereKey($pending->items->first()->id)->exists());
        $this->assertFalse(OrderItem::query()->whereAcceptedAtIsRecorded()->whereKey($processing->items->first()->id)->exists());

        $queue = app(StalledOrderQueue::class);
        $items = $queue->items();

        $this->assertSame(1, $queue->count());
        $this->assertCount(1, $items);
        $this->assertSame($pending->order_number, $items->first()['order_number']);
        $this->assertSame('accept', $items->first()['track']);
    }

    public function test_leftover_accepted_at_gets_accept_nudge_not_publish_nudge(): void
    {
        $publisher = $this->userWithRole('publisher');
        $order = $this->paidOrder(
            $this->userWithRole('advertiser'),
            $this->siteFor($publisher, 'nudge-leftover.example'),
            'pending',
            ['paid_at' => now()->subHours(20)],
            ['accepted_at' => now()->subDays(5)]
        );

        DB::table('order_items')->where('order_id', $order->id)->update([
            'accepted_at' => 'not-a-date',
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertQueued(PublisherAcceptNudge::class);
        Mail::assertNotQueued(PublisherPublishNudge::class);
        $this->assertSame(1, (int) $order->items->first()->fresh()->accept_nudge_stage);
        $this->assertSame(0, (int) $order->items->first()->fresh()->publish_nudge_stage);
    }

    public function test_admin_invoice_show_survives_leftover_due_and_paid_dates(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $invoice = Invoice::create([
            'invoice_number' => 'INV-LO-1',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $advertiser->id,
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'subtotal' => 10,
            'total_amount' => 10,
            'invoice_date' => now(),
            'due_date' => now()->addDays(7),
            'paid_at' => now(),
            'line_items' => [['description' => 'Test', 'line_total' => 10]],
            'pdf_disk' => 'local',
        ]);

        DB::table('invoices')->where('id', $invoice->id)->update([
            'invoice_date' => 'not-a-date',
            'due_date' => 'not-a-date',
            'paid_at' => 'not-a-date',
        ]);

        $invoice->refresh();
        $this->assertNull($invoice->due_date);
        $this->assertNull($invoice->paid_at);

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk();
    }

    public function test_leftover_published_at_is_hidden_from_public_blog(): void
    {
        $author = User::factory()->create();
        $blog = Blog::create([
            'title' => 'Leftover Stamp Post',
            'slug' => 'leftover-stamp-post',
            'content' => '<p>Should not be public with a leftover date.</p>',
            'author' => $author->name,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'created_by' => $author->id,
        ]);

        DB::table('blogs')->where('id', $blog->id)->update([
            'published_at' => 'not-a-date',
        ]);

        $blog->refresh();
        $this->assertNull($blog->published_at);
        $this->assertFalse(Blog::published()->whereKey($blog->id)->exists());

        $this->get(route('blog.index'))
            ->assertOk()
            ->assertDontSee('Leftover Stamp Post', false);
        $this->get(route('blog.show', ['slug' => 'leftover-stamp-post']))
            ->assertNotFound();
    }

    public function test_checkout_intent_leftover_expires_at_does_not_500(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $intents = app(CheckoutIntentService::class);
        $intents->rememberBonus($advertiser->id, 'REF-LO-EXPIRE', 20);

        $row = CheckoutIntent::query()->where('reference_code', 'REF-LO-EXPIRE')->first();
        $this->assertNotNull($row);

        DB::table('checkout_intents')->where('id', $row->id)->update([
            'expires_at' => 'not-a-date',
        ]);

        $row->refresh();
        $this->assertNull($row->expires_at);

        $intents->rememberBonus($advertiser->id, 'REF-LO-EXPIRE', 15);
        $this->assertEqualsWithDelta(15.0, $intents->heldBonus($advertiser->id, 'REF-LO-EXPIRE'), 0.01);
        $this->assertNotNull($row->fresh()->expires_at);
    }

    public function test_leftover_scheduled_publish_at_is_not_released(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'), 'sched-leftover.example');
        $order = $this->paidOrder($advertiser, $site, 'pending', [
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->subHour(),
            'schedule_timezone' => 'UTC',
        ]);

        DB::table('orders')->where('id', $order->id)->update([
            'scheduled_publish_at' => 'not-a-date',
        ]);

        $order->refresh();
        $this->assertNull($order->scheduled_publish_at);
        $this->assertFalse(Order::query()->whereScheduledPublishAtIsRecorded()->whereKey($order->id)->exists());

        $released = app(ScheduledOrderService::class)->releaseDueOrders();

        $this->assertFalse($released->contains('id', $order->id));
        $this->assertNull($order->fresh()->schedule_released_at);
    }
}
