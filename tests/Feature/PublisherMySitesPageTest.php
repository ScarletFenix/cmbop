<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherMySitesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $role = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => "O'Reilly News",
            'site_url' => 'https://oreilly-news.example',
            'domain' => 'oreilly-news.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'description' => "It's a publisher site with apostrophes and \"quotes\".",
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_discount_badges_follow_better_of_and_explain_advertiser_rate(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'price' => 100,
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 15,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        // Configured sale stays on the badge; bulk is hidden when custom wins packs.
        $this->assertStringContainsString('−20%', $html);
        $this->assertStringContainsString('Timed sale −20% (configured)', $html);
        $this->assertStringContainsString('Advertisers see about −11.5%', $html);
        $this->assertStringContainsString('exclusive better-of with bulk, not stacked', $html);
        $this->assertStringNotContainsString('Bulk −15%', $html);
    }

    public function test_my_sites_page_and_ajax_table_render(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
        ]);

        $page = $this->actingAs($this->publisher)->get(route('publisher.websites'));
        $page->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('function fetchSites', $html);
        $this->assertStringContainsString('window.loadSites = fetchSites', $html);
        $this->assertStringContainsString("$(document).on('click', '.action-view'", $html);
        $this->assertStringContainsString("$(document).on('click', '.btn-delete'", $html);
        $this->assertStringContainsString('sitesFilterPending', $html);
        $this->assertStringContainsString('sitesFilterActive', $html);
        $this->assertStringContainsString('ACTIVE_SITES_SEEN_KEY', $html);
        $this->assertStringContainsString('acknowledgeNewActive', $html);
        $this->assertStringContainsString('syncNewActiveBadges', $html);
        $this->assertStringContainsString('initSitePreviewZoom', $html);
        $this->assertStringContainsString('data-glass-tip', $html);
        $this->assertTrue(
            strpos($html, 'id="sitesFilterActive"') < strpos($html, 'id="sitesFilterPending"'),
            'Active filter should appear before Pending'
        );
        $this->assertStringContainsString('Approved / live', $html);
        $this->assertStringContainsString('Bulk drafts with the marketer', $html);
        $this->assertStringContainsString('What Active means', $html);
        $this->assertStringContainsString('What Pending means', $html);
        $this->assertStringNotContainsString('filter-denote', $html);
        $this->assertStringContainsString('let sitesStatusFilter =', $html);
        $this->assertStringContainsString("URLSearchParams(window.location.search).get('status')", $html);
        $this->assertStringContainsString('sitesStatusFilter', $html);
        $this->assertStringNotContainsString('sitesNewActiveBadge', $html);
        $this->assertStringContainsString('openSiteVerificationDialog', $html);
        $this->assertStringContainsString('Verify this website', $html);
        $this->assertStringContainsString('.btn-verify-site', $html);
        $this->assertStringContainsString('verificationErrorTitle', $html);

        $ajax = $this->actingAs($this->publisher)->get(route('publisher.sites.ajax', ['status' => 'active']));
        $ajax->assertOk();
        $ajaxHtml = $ajax->getContent();
        $this->assertTrue(
            str_contains($ajaxHtml, "O'Reilly News") || str_contains($ajaxHtml, 'O&#039;Reilly News'),
            'Ajax table should include the site name'
        );
        $this->assertStringContainsString('btn-edit', $ajaxHtml);
        $this->assertStringNotContainsString('<script', $ajaxHtml);
        $this->assertStringContainsString('🇺🇸', $ajaxHtml);
        $this->assertStringContainsString('sitesStatusMeta', $ajaxHtml);
        $this->assertStringContainsString('site-row-preview', $ajaxHtml);
        $this->assertStringContainsString('site-preview-zoom-pop', $ajaxHtml);
        $this->assertStringContainsString('object-fit: contain', $ajaxHtml);
        $this->assertStringContainsString('padding-top: 62.5%', $ajaxHtml);

        // Desktop 16:10 frame in the Preview column (Safari-safe padding hack).
        // Hover still opens a larger desktop popover.
        $this->assertMatchesRegularExpression(
            '/\.site-row-preview \{[^}]*width: 136px;/s',
            $ajaxHtml
        );
        $this->assertStringContainsString('width:152px;">Preview</th>', $ajaxHtml);
        $this->assertStringNotContainsString('width: 72px', $ajaxHtml);
        $this->assertStringNotContainsString('height: 48px', $ajaxHtml);
        $this->assertStringContainsString('data-label="Preview"', $ajaxHtml);
        $this->assertStringContainsString('>Preview</th>', $ajaxHtml);
        $this->assertStringContainsString('site-row-metrics', $ajaxHtml);
        $this->assertStringContainsString('btn-icon-quiet', $ajaxHtml);
        $this->assertStringContainsString('btn-edit', $ajaxHtml);
        $this->assertStringContainsString('site-status', $ajaxHtml);
        $this->assertStringContainsString('data-glass-tip', $ajaxHtml);
        $this->assertStringContainsString('sites-row-new-badge', $ajaxHtml);
        $this->assertStringContainsString('data-site-new-badge', $ajaxHtml);
        $this->assertStringNotContainsString('yt-tooltip', $ajaxHtml);
        $this->assertDoesNotMatchRegularExpression('/site-row-preview[^>]*(target="_blank"|href=)/', $ajaxHtml);
        $this->assertStringNotContainsString('<strong>Screenshot:</strong>', $ajaxHtml);
        $this->assertStringNotContainsString('btn-warning', $ajaxHtml);
        $this->assertStringNotContainsString('btn-outline-success', $ajaxHtml);
        $this->assertStringNotContainsString('badge bg-info status-badge', $ajaxHtml);
    }

    public function test_ajax_row_shows_screenshot_preview_when_present(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'screenshot_thumb_path' => 'sites/screenshots/thumb-demo.jpg',
            'screenshot_path' => 'sites/screenshots/demo.jpg',
        ]);

        $ajaxHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-row-preview', $ajaxHtml);
        $this->assertStringContainsString('storage/sites/screenshots/thumb-demo.jpg', $ajaxHtml);
        $this->assertStringContainsString('data-zoom-src', $ajaxHtml);
        $this->assertStringContainsString('storage/sites/screenshots/demo.jpg', $ajaxHtml);
        $this->assertStringContainsString('alt="O&#039;Reilly News preview"', $ajaxHtml);
        $this->assertDoesNotMatchRegularExpression('/site-row-preview[^>]*(target="_blank"|href=)/', $ajaxHtml);
    }

    public function test_ajax_filters_pending_and_active_sites(): void
    {
        $pending = $this->makeSite([
            'site_name' => 'Pending Site',
            'site_url' => 'https://pending-site.example',
            'domain' => 'pending-site.example',
            'verified' => false,
            'active' => false,
        ]);
        $active = $this->makeSite([
            'site_name' => 'Active Site',
            'site_url' => 'https://active-site.example',
            'domain' => 'active-site.example',
            'verified' => true,
            'active' => true,
        ]);

        $pendingHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Pending Site', $pendingHtml);
        $this->assertStringNotContainsString('Active Site', $pendingHtml);
        $this->assertStringContainsString('data-pending="1"', $pendingHtml);
        $this->assertStringContainsString('data-active="1"', $pendingHtml);
        $this->assertStringContainsString('data-active-ids="'.$active->id.'"', $pendingHtml);

        $activeHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Active Site', $activeHtml);
        $this->assertStringNotContainsString('Pending Site', $activeHtml);
        $this->assertTrue($pending->id !== $active->id);
    }

    public function test_pending_ajax_shows_bulk_waiting_items_and_stage_chips(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://waiting-a.example',
            'domain' => 'waiting-a.example',
            'price' => 120,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://waiting-b.example',
            'domain' => 'waiting-b.example',
            'price' => 90,
        ]);

        $needsDetails = $this->makeSite([
            'site_name' => 'Needs Details Site',
            'site_url' => 'https://needs-details.example',
            'domain' => 'needs-details.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'verified' => false,
            'active' => false,
        ]);
        $readyReview = $this->makeSite([
            'site_name' => 'Ready Review Site',
            'site_url' => 'https://ready-review.example',
            'domain' => 'ready-review.example',
            'onboarding_status' => Site::ONBOARDING_DETAILS_COMPLETE,
            'verified' => false,
            'active' => false,
        ]);
        $withAdmin = $this->makeSite([
            'site_name' => 'With Admin Site',
            'site_url' => 'https://with-admin.example',
            'domain' => 'with-admin.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'verified' => false,
            'active' => false,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('waiting-a.example', $html);
        $this->assertStringContainsString('waiting-b.example', $html);
        $this->assertStringContainsString('With marketer', $html);
        $this->assertStringContainsString('No edit yet', $html);
        $this->assertStringContainsString('Needs your details', $html);
        $this->assertStringContainsString('Ready to review', $html);
        $this->assertStringContainsString('With admin', $html);
        $this->assertStringContainsString('data-bulk-waiting="2"', $html);
        $this->assertStringContainsString('data-open-bulk="1"', $html);
        // 2 waiting items + 3 pending sites
        $this->assertStringContainsString('data-pending="5"', $html);
        $this->assertStringContainsString((string) $needsDetails->id, $html);
        $this->assertStringContainsString((string) $readyReview->id, $html);
        $this->assertStringContainsString((string) $withAdmin->id, $html);
    }

    public function test_pending_empty_state_mentions_open_bulk_request(): void
    {
        BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'estimated_count' => 5,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bulk request #', $html);
        $this->assertStringContainsString('is in progress', $html);
        $this->assertStringContainsString('data-open-bulk="1"', $html);
        $this->assertStringNotContainsString('No pending sites waiting for admin approval', $html);
    }

    public function test_dual_role_advertiser_active_can_load_pending_sites_ajax(): void
    {
        // Typical marketplace account: Advertiser + Publisher, still active as Advertiser.
        // Deep link / My Sites Pending must auto-activate Publisher instead of 403.
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $user->roles()->attach([$advertiserRole->id, $publisherRole->id]);

        $this->makeSite([
            'publisher_id' => $user->id,
            'site_name' => 'Dual Role Pending',
            'site_url' => 'https://dual-pending.example',
            'domain' => 'dual-pending.example',
            'verified' => false,
            'active' => false,
        ]);

        $this->assertSame('advertiser', $user->fresh()->activeRole());

        $html = $this->actingAs($user)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Dual Role Pending', $html);
        $this->assertSame('publisher', $user->fresh()->activeRole());
    }
}
