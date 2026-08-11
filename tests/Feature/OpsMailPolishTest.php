<?php

namespace Tests\Feature;

use App\Mail\LiveUrlSubmitted;
use App\Mail\OrderAccepted;
use App\Mail\OrderRejected;
use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LiveUrlHealthChecker;
use App\Services\Orders\ReviewHandoffService;
use App\Support\EmailCatalog;
use App\Support\OrderLifecycleMailSuppressor;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 5 — ops polish: welcome catalog copy, lifecycle noise suppression.
 */
class OpsMailPolishTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $advertiser;

    private User $publisher;

    private Site $site;

    private Order $order;

    private OrderItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->swap(LiveUrlHealthChecker::class, new class extends LiveUrlHealthChecker
        {
            public function check(string $url): array
            {
                return [
                    'ok' => true,
                    'status' => 200,
                    'checked_at' => now(),
                    'message' => 'ok',
                ];
            }
        });

        $this->admin = $this->userWithRole('admin');
        $this->advertiser = $this->userWithRole('advertiser');
        $this->publisher = $this->userWithRole('publisher');

        Wallet::create([
            'user_id' => $this->advertiser->id,
            'role_id' => Role::where('name', 'advertiser')->value('id'),
            'balance' => 500,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Ops Polish Site',
            'site_url' => 'https://ops-polish.example',
            'domain' => 'ops-polish.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 2000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Ops mail polish fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-OPS-1',
            'reference_code' => 'REF-OPS-1',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'status' => 'pending',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'paid_at' => now(),
        ]);

        $this->item = OrderItem::create([
            'order_id' => $this->order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://ops-polish.example/article',
            'price' => 100,
            'additional_price' => 0,
            'status' => 'pending',
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

    public function test_email_catalog_marks_welcome_active_without_stale_importance(): void
    {
        $welcome = EmailCatalog::all()['welcome'] ?? [];

        $this->assertSame('active', $welcome['status'] ?? null);
        $this->assertArrayNotHasKey('importance', $welcome);
        $this->assertStringNotContainsString('not auto-sent', strtolower((string) ($welcome['description'] ?? '')));
    }

    public function test_ops_mail_reminders_doc_exists(): void
    {
        $path = base_path('docs/ops-mail-reminders.md');
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('CRON_SECRET', $body);
        $this->assertStringContainsString('MAIL_QUEUE_AUTO_DRAIN', $body);
        $this->assertStringContainsString('PUBLIC_APP_URL', $body);
        $this->assertStringContainsString('schedule:run', $body);
        $this->assertStringContainsString('/cron/run', $body);
    }

    public function test_accept_sends_dedicated_mail_without_advertiser_lifecycle_duplicate(): void
    {
        Mail::fake();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.accept', $this->item->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        Mail::assertQueued(OrderAccepted::class, fn (OrderAccepted $mail) => $mail->hasTo($this->advertiser->email));

        Mail::assertNotQueued(OrderStatusChanged::class, function (OrderStatusChanged $mail) {
            return $mail->hasTo($this->advertiser->email);
        });

        Mail::assertQueued(OrderStatusChanged::class, function (OrderStatusChanged $mail) {
            return $mail->hasTo($this->publisher->email) || $mail->hasTo($this->admin->email);
        });
    }

    public function test_reject_sends_dedicated_mail_without_advertiser_lifecycle_duplicate(): void
    {
        Mail::fake();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $this->item->id), [
                'reason' => 'The niche guidelines do not allow this topic.',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        Mail::assertQueued(OrderRejected::class, fn (OrderRejected $mail) => $mail->hasTo($this->advertiser->email));

        Mail::assertNotQueued(OrderStatusChanged::class, function (OrderStatusChanged $mail) {
            return $mail->hasTo($this->advertiser->email);
        });
    }

    public function test_live_url_submit_sends_dedicated_mail_without_advertiser_lifecycle_duplicate(): void
    {
        Mail::fake();

        $this->order->update(['status' => 'processing']);
        $this->item->update(['accepted_at' => now(), 'publisher_status' => 'accepted']);
        Mail::fake(); // reset lifecycle mail from the processing update above

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $this->item->id), [
                'live_url' => 'https://ops-polish.example/live-post',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        Mail::assertQueued(LiveUrlSubmitted::class, fn (LiveUrlSubmitted $mail) => $mail->hasTo($this->advertiser->email));

        Mail::assertNotQueued(OrderStatusChanged::class, function (OrderStatusChanged $mail) {
            return $mail->hasTo($this->advertiser->email);
        });
    }

    public function test_status_change_without_suppressor_still_notifies_advertiser(): void
    {
        Mail::fake();
        app(OrderLifecycleMailSuppressor::class)->flush();

        $this->order->update(['status' => 'processing']);

        Mail::assertQueued(OrderStatusChanged::class, function (OrderStatusChanged $mail) {
            return $mail->hasTo($this->advertiser->email);
        });
    }

    public function test_noop_live_url_resubmit_clears_suppressor(): void
    {
        $suppressor = app(OrderLifecycleMailSuppressor::class);
        $suppressor->flush();

        $this->order->update(['status' => 'review']);
        $this->item->update([
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'live_url' => 'https://ops-polish.example/old',
            'live_url_submitted_at' => now()->subDay(),
        ]);
        $suppressor->flush();

        Mail::fake();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $this->item->id), [
                'live_url' => 'https://ops-polish.example/live-post-again',
            ])
            ->assertOk();

        $this->assertSame([], $suppressor->audiencesFor($this->order->id));
    }

    public function test_review_handoff_clears_suppressor_without_eloquent_status_event(): void
    {
        $suppressor = app(OrderLifecycleMailSuppressor::class);
        $suppressor->flush();

        $this->order->update(['status' => 'review']);
        $this->item->update([
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'live_url' => 'https://ops-polish.example/old',
            'live_url_submitted_at' => now()->subDay(),
        ]);
        $suppressor->flush();

        Mail::fake();

        app(ReviewHandoffService::class)->handBack(
            $this->item->fresh(),
            $this->site,
            'https://ops-polish.example/handoff',
        );

        $this->assertSame([], $suppressor->audiencesFor($this->order->id));
        Mail::assertQueued(LiveUrlSubmitted::class);
    }
}
