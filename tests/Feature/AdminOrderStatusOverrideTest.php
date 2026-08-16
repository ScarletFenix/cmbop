<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Orders\AdminOrderStatusOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Admins can correct an order that is stuck on the wrong stage. The line held
 * here is money: completing pays the publisher and releases the advertiser's
 * reserved balance, cancelling refunds it, and neither happens by writing to a
 * status column — so those two remain out of reach.
 */
class AdminOrderStatusOverrideTest extends TestCase
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

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Override Site',
            'site_url' => 'https://override.example',
            'domain' => 'override.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1200,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 60,
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function order(User $advertiser, Site $site, string $status = 'processing', string $paymentStatus = 'paid', array $itemExtra = []): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-OVR-'.uniqid(),
            'reference_code' => 'REF-OVR-'.uniqid(),
            'subtotal' => 60,
            'tax' => 0,
            'total_amount' => 60,
            'payment_method' => 'wallet',
            'payment_status' => $paymentStatus,
            'status' => $status,
            'paid_at' => $paymentStatus === 'paid' ? now() : null,
        ]);

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 60,
            'publisher_price' => 50,
            'content_link' => 'https://example.com/article.docx',
        ], $itemExtra));

        return $order->fresh('items');
    }

    private function move(User $admin, Order $order, string $status, string $reason = 'Publisher accepted by mistake.')
    {
        return $this->actingAs($admin)->post(
            route('admin.orders.status', $order->id),
            ['status' => $status, 'reason' => $reason]
        );
    }

    public function test_admin_can_move_a_running_order_between_stages(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'processing');

        $this->move($admin, $order, 'pending')->assertRedirect();

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_completing_an_order_stays_out_of_reach(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->order($advertiser, $site, 'review');

        $publisherRoleId = Role::where('name', 'publisher')->value('id');
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisherRoleId,
            'balance' => 0,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $response = $this->move($admin, $order, 'completed');

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Nothing settled: the order is untouched and the publisher was not paid.
        $this->assertSame('review', $order->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
    }

    public function test_cancelling_an_order_stays_out_of_reach(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'processing');

        $this->move($admin, $order, 'cancelled')->assertSessionHas('error');

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_settled_order_cannot_be_reopened(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'completed');

        $this->move($admin, $order, 'review')->assertSessionHas('error');

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_an_unpaid_order_cannot_be_pushed_into_production(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'pending', 'pending');

        // Publishers should never be told to start work on an unpaid order.
        $this->move($admin, $order, 'processing')->assertSessionHas('error');

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_moving_back_into_review_restarts_the_auto_approve_window(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'processing', 'paid', [
            'live_url' => 'https://override.example/post',
            'live_url_submitted_at' => now()->subDays(9),
            'auto_approve_triggered' => false,
        ]);

        $this->move($admin, $order, 'review', 'Advertiser never got the review prompt.')->assertRedirect();

        // A stale timestamp would let the next cron run complete and pay out
        // within minutes of the move.
        $item = $order->fresh('items')->items->first();
        $this->assertTrue($item->live_url_submitted_at->greaterThan(now()->subMinute()));
    }

    public function test_both_sides_are_told_why_the_order_moved(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->order($advertiser, $site, 'processing');
        $reason = 'Publisher accepted this one by mistake.';

        $this->move($admin, $order, 'pending', $reason)->assertRedirect();

        foreach ([$advertiser, $publisher] as $party) {
            $note = InAppNotification::where('user_id', $party->id)->latest('id')->first();
            $this->assertNotNull($note, "Expected a notification for {$party->email}.");
            $this->assertStringContainsString('updated by support', $note->title);
            $this->assertStringContainsString($reason, (string) $note->message);
        }
    }

    public function test_the_move_is_recorded_against_the_admin(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'processing');

        $this->move($admin, $order, 'review', 'Live URL was submitted out of band.')->assertRedirect();

        $log = ActivityLog::where('action', 'order.status_overridden')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('processing', $log->properties['from']);
        $this->assertSame('review', $log->properties['to']);
        $this->assertSame('Live URL was submitted out of band.', $log->properties['reason']);
    }

    public function test_a_reason_is_required(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'processing');

        $this->actingAs($admin)
            ->post(route('admin.orders.status', $order->id), ['status' => 'review'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_non_admins_cannot_move_orders(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'processing');

        $this->actingAs($advertiser)
            ->post(route('admin.orders.status', $order->id), ['status' => 'review', 'reason' => 'let me in'])
            ->assertForbidden();

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_only_money_free_stages_are_offered(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $override = app(AdminOrderStatusOverride::class);

        $running = $this->order($advertiser, $site, 'processing');
        $this->assertSame(['pending', 'review'], $override->availableFor($running));

        $this->assertSame([], $override->availableFor($this->order($advertiser, $site, 'completed')));
        $this->assertSame([], $override->availableFor($this->order($advertiser, $site, 'cancelled')));
        $this->assertSame([], $override->availableFor($this->order($advertiser, $site, 'pending', 'pending')));
    }

    public function test_status_override_still_succeeds_when_activity_log_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'processing');

        Schema::dropIfExists('activity_logs');

        $this->move($admin, $order, 'pending')->assertRedirect();

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_the_admin_page_offers_the_control_and_explains_the_limit(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'processing');

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Order stage')
            ->assertSee('id="adminOrderStageForm"', false)
            ->assertSee('Completing or cancelling moves money');
    }

    public function test_the_admin_page_explains_why_a_settled_order_is_locked(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $order = $this->order($advertiser, $site, 'completed');

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertDontSee('id="adminOrderStageForm"', false)
            ->assertSee('cannot be reopened from here');
    }
}
