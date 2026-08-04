<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A revision has to be able to come back.
 *
 * The advertiser's Approve button only appears once the order is in review, so
 * an order that cannot get out of the revision state is an order nobody can
 * finish — the publisher waits, the advertiser waits, and the money stays held.
 */
class RevisionRoundTripTest extends TestCase
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
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $roleModel->id]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    /** @return array{0: User, 1: User, 2: Order} */
    private function orderInReview(): array
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        Wallet::create(['user_id' => $advertiser->id, 'role_id' => $advertiser->active_role_id, 'balance' => 0, 'reserved_balance' => 120]);
        Wallet::create(['user_id' => $publisher->id, 'role_id' => $publisher->active_role_id, 'balance' => 0, 'reserved_balance' => 0]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Revision Site',
            'site_url' => 'https://revision.example',
            'domain' => 'revision.example',
            'da' => 30, 'dr' => 35, 'traffic' => 4000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 120,
            'turnaround_time' => '3days',
            'publication_time' => '5 days', 'link_type' => 'dofollow',
            'description' => 'Test site', 'verified' => true, 'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-REV-'.uniqid(),
            'reference_code' => 'REF-REV-'.uniqid(),
            'subtotal' => 120, 'tax' => 0, 'total_amount' => 120,
            'payment_method' => 'wallet', 'payment_status' => 'paid',
            'status' => 'review', 'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 120, 'publisher_price' => 100,
            'content_link' => 'https://example.com/article.docx',
            'accepted_at' => now()->subDay(),
            'live_url' => 'https://revision.example/the-guest-post',
            'live_url_submitted_at' => now()->subHours(2),
            'modification_requested' => 'no',
        ]);

        return [$advertiser, $publisher, $order->fresh('items')];
    }

    public function test_a_revision_can_be_handed_back_and_then_approved(): void
    {
        [$advertiser, $publisher, $order] = $this->orderInReview();
        $item = $order->items->first();

        // Advertiser sends it back.
        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), [
                'reason' => 'The anchor text does not match what we agreed in the brief.',
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame('yes', $item->fresh()->modification_requested);

        // Publisher reports the fix from the task list.
        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.revision-fixed', $item->id))
            ->assertOk();

        $order->refresh();
        $this->assertSame('review', $order->status);
        $this->assertSame('no', $item->fresh()->modification_requested);

        // Which is exactly the state the Approve button requires.
        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk();

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_the_publisher_can_reach_the_handback_from_the_task_list(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/tasks.blade.php'));

        // The revision branch of the row renderer must offer the handback; it
        // used to show only View, Chat and Live, stranding the order.
        $revisionBranch = substr(
            $blade,
            strpos($blade, "} else if (modificationRequested && (orderStatus === 'processing'"),
            900
        );

        $this->assertStringContainsString('chat-revision-fixed-btn', $revisionBranch);
        $this->assertStringContainsString('I have fixed it', $revisionBranch);
    }

    public function test_an_order_still_in_revision_cannot_be_approved(): void
    {
        [$advertiser, , $order] = $this->orderInReview();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), [
                'reason' => 'Please add the second internal link we asked for.',
            ])
            ->assertOk();

        // The publisher has not handed it back, so approving would pay for work
        // that was explicitly rejected.
        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertStatus(400);

        $this->assertSame('processing', $order->fresh()->status);
    }
}
