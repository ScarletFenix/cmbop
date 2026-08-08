<?php

namespace Tests\Feature;

use App\Mail\OrderStatusChanged;
use App\Models\BulkSiteRequest;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderCtaAndOpsBellsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    private User $advertiser;

    private User $publisher;

    private Site $site;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketer = $this->userWithRole('marketing');
        $this->advertiser = $this->userWithRole('advertiser');
        $this->publisher = $this->userWithRole('publisher');

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'CTA Ops Site',
            'site_url' => 'https://cta-ops.example',
            'domain' => 'cta-ops.example',
            'da' => 25,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 90,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('CTA and ops bells fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-CTA-1',
            'reference_code' => 'REF-CTA-1',
            'subtotal' => 90,
            'tax' => 0,
            'total_amount' => 90,
            'status' => 'processing',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $this->order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://cta-ops.example/article',
            'price' => 90,
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

    public function test_order_status_email_ctas_point_at_real_pages(): void
    {
        $publisherMail = new OrderStatusChanged(
            $this->order,
            $this->publisher,
            'publisher',
            'status',
            'pending',
            'processing',
        );
        $publisherHtml = $publisherMail->render();
        $this->assertStringContainsString('/publisher/tasks', $publisherHtml);
        $this->assertStringNotContainsString('/publisher/orders', $publisherHtml);

        $adminMail = new OrderStatusChanged(
            $this->order,
            $this->admin,
            'admin',
            'status',
            'pending',
            'processing',
        );
        $adminHtml = $adminMail->render();
        $this->assertStringContainsString('/admin/orders/'.$this->order->id, $adminHtml);
        $this->assertStringNotContainsString('/admin/payments/'.$this->order->id, $adminHtml);
    }

    public function test_order_lifecycle_mail_does_not_fan_out_to_marketing(): void
    {
        Mail::fake();

        app(EmailNotificationService::class)->notifyOrderLifecycle(
            $this->order->fresh(['user', 'items.site.publisher']),
            'status',
            'pending',
            'processing',
        );

        Mail::assertNotQueued(
            OrderStatusChanged::class,
            fn (OrderStatusChanged $mail) => $mail->hasTo($this->marketer->email)
        );
    }

    public function test_bulk_staff_bell_uses_admin_route_not_marketing_only(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);

        app(InAppNotificationService::class)->notifyStaffBulkSiteRequestSubmitted($bulk);

        $adminNote = InAppNotification::query()
            ->where('user_id', $this->admin->id)
            ->where('related_type', BulkSiteRequest::class)
            ->where('related_id', $bulk->id)
            ->first();
        $this->assertNotNull($adminNote);
        $this->assertStringContainsString('/admin/bulk-site-requests/'.$bulk->id, (string) $adminNote->action_url);
        $this->assertStringNotContainsString('/marketing/bulk-site-requests/', (string) $adminNote->action_url);

        $marketingNote = InAppNotification::query()
            ->where('user_id', $this->marketer->id)
            ->where('related_type', BulkSiteRequest::class)
            ->where('related_id', $bulk->id)
            ->first();
        $this->assertNotNull($marketingNote);
        $this->assertStringContainsString('/admin/bulk-site-requests/'.$bulk->id, (string) $marketingNote->action_url);
    }

    public function test_ops_bells_go_to_admin_not_marketing(): void
    {
        $notifications = app(InAppNotificationService::class);
        $item = $this->order->items()->firstOrFail();

        $notifications->notifyAdminsStalledOrder($this->order, $item, $this->publisher, 'accept', 96);
        $notifications->notifyAdminsCatalogPace($this->advertiser, 40, 30, 'review');
        $notifications->notifyAdminsNewUser($this->advertiser);

        $dispute = OrderItemDispute::create([
            'order_id' => $this->order->id,
            'order_item_id' => $item->id,
            'opened_by' => $this->advertiser->id,
            'reason' => 'Live link removed from the article.',
            'status' => OrderItemDispute::STATUS_OPEN,
        ]);
        $notifications->notifyDisputeOpened($dispute);

        $titles = [
            'Order #'.$this->order->order_number.' needs attention',
            'Heavy catalog activity',
            'New advertiser registered',
            'Dispute opened on order #'.$this->order->order_number,
        ];

        $this->assertSame(
            4,
            InAppNotification::query()
                ->where('user_id', $this->admin->id)
                ->whereIn('title', $titles)
                ->count()
        );

        $this->assertSame(
            0,
            InAppNotification::query()
                ->where('user_id', $this->marketer->id)
                ->whereIn('title', $titles)
                ->count()
        );

        // Site review bells remain shared with marketing.
        $notifications->notifyAdminsNewSite($this->site, 'create');
        $this->assertSame(
            1,
            InAppNotification::query()
                ->where('user_id', $this->marketer->id)
                ->where('related_type', Site::class)
                ->where('related_id', $this->site->id)
                ->count()
        );
    }
}
