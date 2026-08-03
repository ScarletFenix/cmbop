<?php

namespace Tests\Feature;

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
    }
}
