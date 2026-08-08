<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BellOrderDeepLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    private Site $site;

    private Order $order;

    private OrderItem $item;

    private InAppNotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->advertiser = $this->userWithRole('advertiser');
        $this->publisher = $this->userWithRole('publisher');
        $this->notifications = app(InAppNotificationService::class);

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Bell Deep Link Site',
            'site_url' => 'https://bell-deep.example',
            'domain' => 'bell-deep.example',
            'da' => 28,
            'dr' => 28,
            'traffic' => 1200,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 95,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Bell order deep link fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-BELL-DEEP-1',
            'reference_code' => 'REF-BELL-DEEP-1',
            'subtotal' => 95,
            'tax' => 0,
            'total_amount' => 95,
            'status' => 'processing',
            'payment_status' => 'pending',
            'payment_method' => 'wise',
            'paid_at' => null,
        ]);

        $this->item = OrderItem::create([
            'order_id' => $this->order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://bell-deep.example/article',
            'price' => 95,
            'additional_price' => 0,
            'status' => 'processing',
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function assertPublisherTasksDeepLink(?InAppNotification $note): void
    {
        $this->assertNotNull($note);
        $url = (string) $note->action_url;
        $this->assertStringContainsString('/publisher/tasks', $url);
        $this->assertStringContainsString('focus=order', $url);
        $this->assertStringContainsString('order='.$this->order->id, $url);
    }

    public function test_payment_pending_bell_deep_links_to_the_order(): void
    {
        $this->notifications->notifyPaymentPending($this->order);

        $note = InAppNotification::query()
            ->where('user_id', $this->advertiser->id)
            ->where('type', InAppNotificationService::TYPE_PAYMENT_PENDING)
            ->latest('id')
            ->first();

        $this->assertNotNull($note);
        $url = (string) $note->action_url;
        $this->assertStringContainsString('/advertiser/orders', $url);
        $this->assertStringContainsString('payment_status=pending', $url);
        $this->assertStringContainsString('focus=order', $url);
        $this->assertStringContainsString('order='.$this->order->id, $url);
    }

    public function test_publisher_nudge_and_schedule_bells_deep_link_to_tasks_order(): void
    {
        $this->notifications->notifyPublisherAcceptNudge($this->order, $this->item, $this->publisher, 2);
        $this->notifications->notifyPublisherPublishNudge($this->order, $this->item, $this->publisher, 2, 36);
        $this->notifications->notifyScheduledPublishDue($this->order->fresh(['items.site']));

        $accept = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Accept order #'.$this->order->order_number)
            ->latest('id')
            ->first();
        $this->assertPublisherTasksDeepLink($accept);

        $publish = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Overdue — order #'.$this->order->order_number)
            ->latest('id')
            ->first();
        $this->assertPublisherTasksDeepLink($publish);

        $scheduled = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Publish today — order #'.$this->order->order_number)
            ->latest('id')
            ->first();
        $this->assertPublisherTasksDeepLink($scheduled);
    }
}
