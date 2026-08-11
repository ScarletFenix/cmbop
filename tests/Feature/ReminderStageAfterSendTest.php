<?php

namespace Tests\Feature;

use App\Mail\PublisherAcceptNudge;
use App\Models\EmailNotificationPreference;
use App\Models\EmailNotificationSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 2.1 — advance nudge stages only after the mailer accepts the message.
 */
class ReminderStageAfterSendTest extends TestCase
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
            'site_name' => 'Stage Site '.uniqid(),
            'site_url' => 'https://stage-'.uniqid().'.example',
            'domain' => 'stage-'.uniqid().'.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 5000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Stage-after-send fixture',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function pendingPaidOrder(User $advertiser, Site $site, array $orderExtra = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-STG-'.uniqid(),
            'reference_code' => 'REF-STG-'.uniqid(),
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now()->subHours(20),
        ], $orderExtra));

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'publisher_price' => 85,
            'content_link' => 'https://example.com/article.docx',
            'accept_nudge_stage' => 0,
        ]);

        return $order->fresh('items');
    }

    public function test_successful_accept_nudge_advances_stage(): void
    {
        $publisher = $this->userWithRole('publisher');
        $order = $this->pendingPaidOrder($this->userWithRole('advertiser'), $this->site($publisher));

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertQueued(PublisherAcceptNudge::class);
        $this->assertSame(1, (int) $order->items->first()->fresh()->accept_nudge_stage);
        $this->assertNotNull($order->items->first()->fresh()->accept_nudge_sent_at);
    }

    public function test_preference_off_leaves_accept_stage_unchanged(): void
    {
        $publisher = $this->userWithRole('publisher');
        $order = $this->pendingPaidOrder($this->userWithRole('advertiser'), $this->site($publisher));

        EmailNotificationPreference::create([
            'user_id' => $publisher->id,
            'preference_key' => 'order_emails',
            'enabled' => false,
        ]);

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertNotQueued(PublisherAcceptNudge::class);
        $this->assertSame(0, (int) $order->items->first()->fresh()->accept_nudge_stage);
        $this->assertNull($order->items->first()->fresh()->accept_nudge_sent_at);
    }

    public function test_admin_kill_switch_leaves_accept_stage_unchanged(): void
    {
        $publisher = $this->userWithRole('publisher');
        $order = $this->pendingPaidOrder($this->userWithRole('advertiser'), $this->site($publisher));

        $setting = EmailNotificationSetting::query()->firstOrNew(['type' => 'publisher_accept_nudge']);
        $setting->enabled = false;
        $setting->save();
        EmailNotificationSetting::flushCache('publisher_accept_nudge');

        $this->artisan('orders:nudge-publishers')->assertSuccessful();

        Mail::assertNotQueued(PublisherAcceptNudge::class);
        $this->assertSame(0, (int) $order->items->first()->fresh()->accept_nudge_stage);
    }
}
