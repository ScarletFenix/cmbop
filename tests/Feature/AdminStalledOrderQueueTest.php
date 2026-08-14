<?php

namespace Tests\Feature;

use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherPublishNudge;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * When the automated cadence runs out, an admin has to decide: chase once more,
 * or refund the advertiser. The queue exists so that decision has the facts in
 * front of it, and so an order cannot go quiet simply because the emails ran out.
 */
class AdminStalledOrderQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
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

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Stalled Site '.uniqid(),
            'site_url' => 'https://stalled-'.uniqid().'.example',
            'domain' => 'stalled-'.uniqid().'.example',
            'da' => 35,
            'dr' => 40,
            'traffic' => 5000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '24h',
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Stalled test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $itemExtra
     */
    private function order(User $advertiser, Site $site, string $status, array $itemExtra = []): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-STL-'.uniqid(),
            'reference_code' => 'REF-STL-'.uniqid(),
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => $status,
            'paid_at' => now()->subDays(6),
        ]);

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'publisher_price' => 85,
            'content_link' => 'https://example.com/article.docx',
        ], $itemExtra));

        return $order->fresh('items');
    }

    public function test_an_exhausted_order_appears_in_the_queue(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'processing', [
            'accepted_at' => now()->subDays(5),
            'publish_nudge_stage' => 4,
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.dashboard.stalled-orders'));

        $response->assertOk()->assertJson(['success' => true, 'count' => 1]);
        $response->assertJsonPath('items.0.order_number', $order->order_number);
        $response->assertJsonPath('items.0.track', 'publish');
        $response->assertJsonPath('items.0.order_url', route('admin.orders.show', $order->id));

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('stalled_orders', 1)
            ->assertJsonPath('needs_attention', 1);
    }

    public function test_an_order_still_working_through_the_cadence_is_not_escalated_yet(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'processing', [
            'accepted_at' => now()->subDays(2),
            // Two reminders in; the automated ladder has stages left to try.
            'publish_nudge_stage' => 2,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.stalled-orders'))
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 0]);
    }

    public function test_an_order_never_accepted_is_escalated_too(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [
            'accept_nudge_stage' => 3,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.stalled-orders'))
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 1])
            ->assertJsonPath('items.0.track', 'accept');
    }

    public function test_an_upcoming_scheduled_order_is_not_in_the_stalled_queue(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [
            'accept_nudge_stage' => 3,
        ]);
        $order->update([
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.stalled-orders'))
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 0]);
    }

    public function test_a_manual_chase_is_refused_while_the_order_is_still_scheduled(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [
            'accept_nudge_stage' => 3,
        ]);
        $order->update([
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.orders.remind-publisher', $order->items->first()->id))
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'This order is still scheduled. Remind the publisher after it is released.',
            ]);

        Mail::assertNotQueued(PublisherAcceptNudge::class);
        Mail::assertNotQueued(PublisherPublishNudge::class);
    }

    public function test_the_queue_is_closed_to_everyone_but_admins(): void
    {
        $publisher = $this->userWithRole('publisher');

        $this->actingAs($publisher)
            ->getJson(route('admin.dashboard.stalled-orders'))
            ->assertStatus(403);
    }

    public function test_an_admin_can_chase_the_publisher_without_waiting_for_the_scheduler(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'processing', [
            'accepted_at' => now()->subDays(5),
            'publish_nudge_stage' => 4,
        ]);
        $item = $order->items->first();

        $this->actingAs($admin)
            ->postJson(route('admin.orders.remind-publisher', $item->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        Mail::assertQueued(PublisherPublishNudge::class, fn ($mail) => $mail->hasTo($publisher->email));
        Mail::assertNotQueued(PublisherAcceptNudge::class);
    }

    public function test_chasing_by_hand_does_not_consume_the_automated_escalation(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'processing', [
            'accepted_at' => now()->subDays(3),
            'publish_nudge_stage' => 2,
        ]);
        $item = $order->items->first();

        $this->actingAs($admin)
            ->postJson(route('admin.orders.remind-publisher', $item->id))
            ->assertOk();

        // A couple of manual chases must not silently burn through the stages the
        // scheduled command is counting on.
        $this->assertSame(2, (int) $item->fresh()->publish_nudge_stage);
    }

    public function test_a_manual_chase_is_recorded_against_the_order(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'processing', [
            'accepted_at' => now()->subDays(5),
            'publish_nudge_stage' => 4,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.orders.remind-publisher', $order->items->first()->id))
            ->assertOk();

        $this->assertTrue(
            ActivityLog::where('action', 'order.publisher_reminded')
                ->where('subject_id', $order->id)
                ->exists()
        );
    }

    public function test_only_admins_can_send_a_manual_reminder(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($advertiser, $this->site($publisher), 'processing', [
            'accepted_at' => now()->subDays(5),
            'publish_nudge_stage' => 4,
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('admin.orders.remind-publisher', $order->items->first()->id))
            ->assertStatus(403);

        Mail::assertNotQueued(PublisherPublishNudge::class);
        Mail::assertNotQueued(PublisherAcceptNudge::class);
    }

    public function test_remind_fails_clearly_when_the_publisher_has_no_email(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $publisher->email = '';
        $publisher->save();
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'processing', [
            'accepted_at' => now()->subDays(5),
            'publish_nudge_stage' => 4,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.orders.remind-publisher', $order->items->first()->id))
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'No publisher email on file for this order.',
            ]);

        Mail::assertNotQueued(PublisherPublishNudge::class);
        Mail::assertNotQueued(PublisherAcceptNudge::class);
    }

    public function test_an_unaccepted_order_is_chased_with_an_accept_nudge(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [
            'accept_nudge_stage' => 3,
        ]);
        $item = $order->items->first();

        $this->actingAs($admin)
            ->postJson(route('admin.orders.remind-publisher', $item->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        Mail::assertQueued(PublisherAcceptNudge::class, fn ($mail) => $mail->hasTo($publisher->email));
        Mail::assertNotQueued(PublisherPublishNudge::class);

        $log = ActivityLog::where('action', 'order.publisher_reminded')
            ->where('subject_id', $order->id)
            ->first();
        $this->assertSame('accept', $log?->properties['track'] ?? null);
    }

    public function test_chasing_an_unaccepted_order_does_not_consume_the_accept_cadence(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [
            'accept_nudge_stage' => 3,
        ]);
        $item = $order->items->first();

        $this->actingAs($admin)
            ->postJson(route('admin.orders.remind-publisher', $item->id))
            ->assertOk();

        $this->assertSame(3, (int) $item->fresh()->accept_nudge_stage);
    }

    public function test_stalled_count_is_not_capped_by_the_table_limit(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site($publisher);

        for ($i = 0; $i < 26; $i++) {
            $this->order($advertiser, $site, 'processing', [
                'accepted_at' => now()->subDays(5),
                'publish_nudge_stage' => 4,
            ]);
        }

        $response = $this->actingAs($admin)->getJson(route('admin.dashboard.stalled-orders'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 26);
        $this->assertCount(25, $response->json('items'));
    }

    public function test_recently_overdue_stalled_orders_show_hours_not_a_full_day(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($this->userWithRole('advertiser'), $this->site($publisher), 'pending', [
            'accept_nudge_stage' => 3,
        ]);
        $order->paid_at = now()->subHours(5);
        $order->save();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.stalled-orders'))
            ->assertOk()
            ->assertJsonPath('items.0.late_label', '5h')
            ->assertJsonPath('items.0.days_overdue', 0);
    }
}
