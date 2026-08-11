<?php

namespace Tests\Feature;

use App\Mail\AdvertiserReviewNudge;
use App\Mail\WelcomeEmail;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 3 — role-aware welcome copy/CTA and mid-window review bell parity.
 */
class WelcomeAndReviewBellParityTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    public function test_verified_advertiser_welcome_points_at_catalog_with_bonus_copy(): void
    {
        $user = $this->userWithRole('advertiser');

        $mailable = new WelcomeEmail($user);
        $built = $mailable->build();
        $html = $mailable->render();

        $this->assertSame('advertiser', $built->viewData['workspace'] ?? null);
        $this->assertSame('Browse Websites', $built->viewData['ctaLabel'] ?? null);
        $this->assertStringContainsString('/advertiser/catalog', (string) ($built->viewData['ctaUrl'] ?? ''));
        $this->assertStringNotContainsString('/publisher/websites', (string) ($built->viewData['ctaUrl'] ?? ''));
        $this->assertStringContainsString('€20 welcome credit', $html);
        $this->assertStringNotContainsString('list your first website', strtolower($html));
    }

    public function test_verified_publisher_welcome_points_at_my_sites(): void
    {
        $user = $this->userWithRole('publisher');

        $mailable = new WelcomeEmail($user);
        $built = $mailable->build();
        $html = $mailable->render();

        $this->assertSame('publisher', $built->viewData['workspace'] ?? null);
        $this->assertSame('Add your first website', $built->viewData['ctaLabel'] ?? null);
        $this->assertStringContainsString('/publisher/websites', (string) ($built->viewData['ctaUrl'] ?? ''));
        $this->assertStringNotContainsString('/advertiser/catalog', (string) ($built->viewData['ctaUrl'] ?? ''));
        $this->assertStringContainsString('list your first website', strtolower(strip_tags($html)));
        $this->assertStringNotContainsString('€20 welcome credit', $html);
    }

    public function test_unverified_publisher_welcome_still_uses_verify_cta(): void
    {
        $user = $this->userWithRole('publisher', ['email_verified_at' => null]);

        $built = (new WelcomeEmail($user))->build();

        $this->assertSame('Click to verify', $built->viewData['ctaLabel'] ?? null);
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', (string) ($built->viewData['ctaUrl'] ?? ''));
        $this->assertTrue((bool) ($built->viewData['needsVerification'] ?? false));
        // Workspace is still publisher so post-verify copy context is available.
        $this->assertSame('publisher', $built->viewData['workspace'] ?? null);
    }

    public function test_dual_role_welcome_follows_active_starting_workspace(): void
    {
        $advertiser = Role::firstOrCreate(['name' => 'advertiser']);
        $publisher = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisher->id,
        ]);
        $user->roles()->sync([$advertiser->id, $publisher->id]);

        $built = (new WelcomeEmail($user->fresh()))->build();

        $this->assertSame('publisher', $built->viewData['workspace'] ?? null);
        $this->assertStringContainsString('/publisher/websites', (string) ($built->viewData['ctaUrl'] ?? ''));
    }

    public function test_mid_window_review_nudge_creates_bell_with_order_deep_link(): void
    {
        Mail::fake();

        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Review Bell Site',
            'site_url' => 'https://review-bell.example',
            'domain' => 'review-bell.example',
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
            'description' => 'Mid-window review bell fixture',
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-REV-BELL',
            'reference_code' => 'REF-REV-BELL',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now()->subDays(3),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'publisher_price' => 85,
            'content_link' => 'https://example.com/article.docx',
            'accepted_at' => now()->subDays(2),
            'live_url' => 'https://review-bell.example/live-post',
            'live_url_submitted_at' => now()->subHours(30),
            'modification_requested' => 'no',
        ]);

        $this->artisan('orders:nudge-advertisers')->assertSuccessful();

        Mail::assertQueued(AdvertiserReviewNudge::class, fn ($mail) => $mail->hasTo($advertiser->email));

        $bell = InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('audience', InAppNotification::AUDIENCE_ADVERTISER)
            ->where('type', InAppNotificationService::TYPE_ORDER_UPDATED)
            ->latest('id')
            ->first();

        $this->assertNotNull($bell);
        $this->assertStringContainsString('Your link is live', (string) $bell->title);
        $this->assertStringContainsString('focus=order', (string) $bell->action_url);
        $this->assertStringContainsString('order='.$order->id, (string) $bell->action_url);
    }
}
