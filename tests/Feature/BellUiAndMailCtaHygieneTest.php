<?php

namespace Tests\Feature;

use App\Mail\AdminNewUserRegistered;
use App\Mail\ContentEvaluationResult;
use App\Mail\DepositReminderMail;
use App\Mail\MonthlySpendingSummary;
use App\Mail\NewSitesDigest;
use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherAddSiteReminderMail;
use App\Mail\PublisherPublishNudge;
use App\Mail\WeeklyActivitySummary;
use App\Mail\WelcomeEmail;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\EmailCatalog;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Phase 4 — bell dropdown filters/search + reminder CTA named-route hygiene.
 */
class BellUiAndMailCtaHygieneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_notification_center_dropdown_exposes_filter_chips_and_search(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-nc-search', false)
            ->assertSee('data-nc-filter="all"', false)
            ->assertSee('data-nc-filter="unread"', false)
            ->assertSee('data-nc-filter="orders"', false)
            ->assertSee('data-nc-filter="messages"', false)
            ->assertSee('data-nc-filter="payments"', false)
            ->assertSee('data-nc-filter="system"', false)
            ->assertSee('placeholder="Search notifications…"', false);
    }

    public function test_notification_center_js_still_wires_filter_and_search_selectors(): void
    {
        foreach ([
            public_path('js/notification-center.js'),
            public_path('assets/js/notification-center.js'),
        ] as $path) {
            $this->assertFileExists($path);
            $js = (string) file_get_contents($path);
            $this->assertStringContainsString('[data-nc-filter]', $js);
            $this->assertStringContainsString('[data-nc-search]', $js);
            $this->assertStringContainsString('aria-selected', $js);
        }
    }

    public function test_reminder_and_digest_mailables_avoid_hardcoded_url_helpers(): void
    {
        $files = [
            app_path('Mail/DepositReminderMail.php'),
            app_path('Mail/PublisherAddSiteReminderMail.php'),
            app_path('Mail/ContentEvaluationResult.php'),
            app_path('Mail/AdminNewUserRegistered.php'),
            app_path('Mail/MonthlySpendingSummary.php'),
            app_path('Mail/WeeklyActivitySummary.php'),
            app_path('Mail/NewSitesDigest.php'),
            app_path('Mail/PublisherAcceptNudge.php'),
            app_path('Mail/PublisherPublishNudge.php'),
            app_path('Mail/WelcomeEmail.php'),
            app_path('Mail/OrderStatusChanged.php'),
            app_path('Mail/PlatformMailable.php'),
        ];

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                "/url\\(\\s*['\\\"]\\//",
                $src,
                basename($file).' still uses url(\'/…\') — prefer publicRoute()/named routes.'
            );
        }
    }

    public function test_onboarding_and_digest_ctas_use_named_route_paths(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $admin = $this->userWithRole('admin');

        $deposit = (new DepositReminderMail($advertiser, DepositReminderMail::STEP_DAY7))->render();
        $this->assertStringContainsString('/advertiser/add-funds', $deposit);
        $this->assertStringContainsString('/advertiser/catalog', $deposit);

        $addSite = (new PublisherAddSiteReminderMail($publisher, PublisherAddSiteReminderMail::STEP_DAY3))->render();
        $this->assertStringContainsString('/publisher/websites', $addSite);

        $welcome = (new WelcomeEmail($advertiser))->render();
        $this->assertStringContainsString('/advertiser/catalog', $welcome);

        $adminMail = (new AdminNewUserRegistered($advertiser, $admin))->render();
        $this->assertStringContainsString('/admin/audiences', $adminMail);
        $this->assertStringContainsString('tab=no_orders', $adminMail);

        $weekly = (new WeeklyActivitySummary($advertiser))->render();
        $this->assertStringContainsString('/advertiser/analytics', $weekly);

        $monthly = (new MonthlySpendingSummary($advertiser, ['month_label' => 'August 2026']))->render();
        $this->assertStringContainsString('/advertiser/analytics', $monthly);

        $digest = (new NewSitesDigest($advertiser, collect()))->render();
        $this->assertStringContainsString('/advertiser/catalog', $digest);
    }

    public function test_publisher_nudge_ctas_deep_link_via_public_route_helper(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'CTA Hygiene Site',
            'site_url' => 'https://cta-hygiene.example',
            'domain' => 'cta-hygiene.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 800,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('CTA hygiene fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CTA-HYG-1',
            'reference_code' => 'REF-CTA-HYG-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'status' => 'processing',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'paid_at' => now(),
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://cta-hygiene.example/article',
            'price' => 80,
            'additional_price' => 0,
            'status' => 'processing',
        ]);

        $accept = (new PublisherAcceptNudge($publisher, $order, $item, $site, 1, 12))->render();
        $this->assertStringContainsString('/publisher/tasks', $accept);
        $this->assertStringContainsString('order='.$order->id, $accept);

        $publish = (new PublisherPublishNudge($publisher, Collection::make([[
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'site_name' => $site->site_name,
            'due_at' => now()->subDay(),
            'hours_overdue' => 24,
            'overdue_label' => '24h late',
            'promised' => '3days',
            'payout' => 70.0,
        ]]), 2, 'test'))->render();
        $this->assertStringContainsString('/publisher/tasks', $publish);
        $this->assertStringContainsString('order='.$order->id, $publish);
    }

    public function test_content_evaluation_cta_uses_content_library_route(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $submission = ContentSubmission::create([
            'user_id' => $advertiser->id,
            'title' => 'CTA hygiene article',
            'original_filename' => 'article.docx',
            'disk' => 'local',
            'path' => 'content-uploads/cta-hygiene.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => 100,
            'moderation_status' => ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
        ]);

        $html = (new ContentEvaluationResult($submission, [
            'approved' => false,
            'moderation_status' => 'needs_changes',
            'summary' => 'Please revise the intro.',
        ]))->render();

        $this->assertStringContainsString('/advertiser/content-library', $html);
    }

    public function test_email_catalog_marks_welcome_as_active(): void
    {
        $welcome = EmailCatalog::all()['welcome'] ?? [];
        $this->assertSame('active', $welcome['status'] ?? null);
        $this->assertArrayNotHasKey('importance', $welcome);
        $this->assertStringContainsString('registration', strtolower((string) ($welcome['description'] ?? '')));
    }
}
