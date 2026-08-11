<?php

namespace Tests\Feature;

use App\Mail\AutoApproveReminderMail;
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
 * Phase 0 mail foundations: single schedule source + dedicated auto-approve
 * reminder notification type (independent of order_status_changed).
 */
class MailScheduleAndAutoApproveTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_kernel_does_not_duplicate_bootstrap_schedule_commands(): void
    {
        $kernel = (string) file_get_contents(app_path('Console/Kernel.php'));
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('bootstrap/app.php', $kernel);
        $this->assertStringNotContainsString("command('orders:auto-approve')", $kernel);
        $this->assertStringNotContainsString("command('emails:send-publisher-add-site-reminders')", $kernel);

        $this->assertStringContainsString("command('orders:auto-approve')", $bootstrap);
        $this->assertStringContainsString("command('emails:send-publisher-add-site-reminders')", $bootstrap);
    }

    public function test_auto_approve_reminder_mail_uses_dedicated_notification_type(): void
    {
        $mail = new AutoApproveReminderMail(
            new Order(['order_number' => 'ORD-TEST']),
            new OrderItem(['id' => 1, 'site_name' => 'Example']),
            null,
            24
        );

        $this->assertSame('auto_approve_reminder', $mail->notificationType);
        $this->assertNotSame('order_status_changed', $mail->notificationType);
        $this->assertSame(AutoApproveReminderMail::class, config('email_notifications.types.auto_approve_reminder.mailable'));
        $this->assertTrue((bool) config('email_notifications.types.auto_approve_reminder.default_enabled'));
    }

    public function test_disabling_order_status_changed_still_queues_auto_approve_reminder(): void
    {
        Mail::fake();
        config([
            'orders.auto_approve_hours' => 72,
            'orders.auto_approve_reminder_hours_before' => 24,
            'orders.auto_approve_require_live_url_ok' => true,
        ]);

        $setting = EmailNotificationSetting::query()->firstOrNew(['type' => 'order_status_changed']);
        $setting->enabled = false;
        $setting->save();
        EmailNotificationSetting::flushCache('order_status_changed');
        EmailNotificationSetting::flushCache('auto_approve_reminder');

        $this->assertFalse(EmailNotificationSetting::isEnabled('order_status_changed'));
        $this->assertTrue(EmailNotificationSetting::isEnabled('auto_approve_reminder'));

        $this->seedReviewOrderReadyForReminder('ORD-AA-TYPE-1');

        $this->artisan('orders:auto-approve')->assertSuccessful();

        Mail::assertQueued(AutoApproveReminderMail::class, function (AutoApproveReminderMail $mail) {
            return $mail->notificationType === 'auto_approve_reminder';
        });
    }

    public function test_disabling_auto_approve_reminder_type_suppresses_the_mail(): void
    {
        config([
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'email_notifications.queue_connection' => 'sync',
            'orders.auto_approve_hours' => 72,
            'orders.auto_approve_reminder_hours_before' => 24,
            'orders.auto_approve_require_live_url_ok' => true,
        ]);

        $setting = EmailNotificationSetting::query()->firstOrNew(['type' => 'auto_approve_reminder']);
        $setting->enabled = false;
        $setting->save();
        EmailNotificationSetting::flushCache('auto_approve_reminder');

        $this->assertFalse(EmailNotificationSetting::isEnabled('auto_approve_reminder'));

        $order = $this->seedReviewOrderReadyForReminder('ORD-AA-TYPE-OFF');

        $this->artisan('orders:auto-approve')->assertSuccessful();

        $this->assertDatabaseMissing('email_logs', [
            'notification_type' => 'auto_approve_reminder',
        ]);
        // Stage still advances today (send-before-stage flip is Phase 2).
        $this->assertNotNull($order->items()->first()->fresh()->auto_approve_reminder_sent_at);
    }

    private function seedReviewOrderReadyForReminder(string $orderNumber): Order
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Reminder Site '.$orderNumber,
            'site_url' => 'https://reminder-'.strtolower($orderNumber).'.test',
            'domain' => 'reminder-'.strtolower($orderNumber).'.test',
            'da' => 40,
            'dr' => 40,
            'traffic' => 20000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'en',
            'languages' => ['en'],
            'category' => 'Marketing',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Fixture for auto-approve reminder type.',
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => $orderNumber,
            'reference_code' => 'REF-'.$orderNumber,
            'status' => 'review',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'paid_at' => now()->subDays(3),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'publisher_id' => $publisher->id,
            'content_link' => 'https://example.com/article.docx',
            'status' => 'review',
            'live_url' => $site->site_url.'/live',
            'live_url_submitted_at' => now()->subHours(49),
            'live_url_check_ok' => true,
            'modification_requested' => 'no',
            'auto_approve_triggered' => false,
            'auto_approve_reminder_sent_at' => null,
        ]);

        return $order;
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user->fresh();
    }
}
