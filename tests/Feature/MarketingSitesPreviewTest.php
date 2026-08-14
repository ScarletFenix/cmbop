<?php

namespace Tests\Feature;

use App\Jobs\CaptureSiteScreenshotJob;
use App\Jobs\EnrichSiteJob;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketingSitesPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Storage::fake('public');

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Marketing Preview Site',
            'site_url' => 'https://mkt-preview.example',
            'domain' => 'mkt-preview.example',
            'da' => 20,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'price' => 50,
            'publication_time' => 'permanent',
            'description' => 'Marketing sites row preview regression',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_user_sites_json_includes_site_image_in_fallback_chain(): void
    {
        $site = $this->makeSite([
            // Stale capture paths — client onerror walks to the upload.
            'screenshot_thumb_path' => 'site-screenshots/missing-thumb.webp',
            'screenshot_path' => 'site-screenshots/missing-full.webp',
            'site_image' => 'sites/cover-real.webp',
        ]);

        $json = $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', $this->publisher->id))
            ->assertOk()
            ->json();

        $row = collect($json['sites'] ?? [])->firstWhere('id', $site->id);
        $this->assertIsArray($row);
        // Uploaded cover wins list thumb over stale/missing auto-screenshots.
        // Staff previews use the auth'd disk-stream route (Hostinger-safe).
        $this->assertSame('/marketing/sites/media/sites/cover-real.webp', $row['preview_thumb_url']);
        $this->assertContains('/marketing/sites/media/sites/cover-real.webp', $row['preview_fallback_urls']);
        $this->assertContains('/storage/sites/cover-real.webp', $row['preview_fallback_urls']);
        $this->assertSame('/marketing/sites/media/sites/cover-real.webp', $row['image_url']);
        $this->assertArrayNotHasKey('verify_token', $row);
    }

    public function test_user_sites_json_returns_screenshot_urls_when_present_on_disk(): void
    {
        Storage::disk('public')->put('site-screenshots/home-thumb.webp', 'thumb');
        Storage::disk('public')->put('site-screenshots/home-full.webp', 'full');

        $site = $this->makeSite([
            'site_name' => 'Shot Preview Site',
            'site_url' => 'https://shot-preview.example',
            'domain' => 'shot-preview.example',
            'screenshot_thumb_path' => 'site-screenshots/home-thumb.webp',
            'screenshot_path' => 'site-screenshots/home-full.webp',
        ]);

        $row = $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', $this->publisher->id))
            ->assertOk()
            ->json('sites.0');

        $this->assertSame($site->id, $row['id']);
        // List uses staff media stream; zoom/detail prefers full desktop capture URL.
        $this->assertSame('/marketing/sites/media/site-screenshots/home-thumb.webp', $row['preview_thumb_url']);
        $this->assertSame('/marketing/sites/media/site-screenshots/home-full.webp', $row['preview_full_url']);
        $this->assertNotSame($row['preview_thumb_url'], $row['preview_full_url']);
    }

    public function test_user_sites_json_emits_declared_paths_without_disk_exists_check(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Declared Paths Site',
            'site_url' => 'https://declared-paths.example',
            'domain' => 'declared-paths.example',
            'screenshot_thumb_path' => 'site-screenshots/gone-thumb.webp',
            'screenshot_path' => 'site-screenshots/gone-full.webp',
            'site_image' => 'sites/gone-upload.webp',
        ]);

        $row = $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', $this->publisher->id))
            ->assertOk()
            ->json('sites.0');

        $this->assertSame($site->id, $row['id']);
        // Fast list path: emit URLs from DB; browser onerror handles 404s.
        // Uploaded cover is preferred for the list thumb when present.
        $this->assertSame('/marketing/sites/media/sites/gone-upload.webp', $row['preview_thumb_url']);
        $this->assertSame('/marketing/sites/media/site-screenshots/gone-full.webp', $row['preview_full_url']);
        $this->assertContains('/marketing/sites/media/sites/gone-upload.webp', $row['preview_fallback_urls']);
        $this->assertContains('/storage/sites/gone-upload.webp', $row['preview_fallback_urls']);
    }

    public function test_enrich_and_screenshot_default_to_queued_jobs(): void
    {
        Bus::fake();

        $site = $this->makeSite([
            'site_name' => 'Queue Enrich Site',
            'site_url' => 'https://queue-enrich.example',
            'domain' => 'queue-enrich.example',
        ]);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.enrich', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Enrichment queued');

        Bus::assertDispatched(EnrichSiteJob::class);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.refresh-screenshot', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Screenshot refresh queued');

        Bus::assertDispatched(CaptureSiteScreenshotJob::class);
    }

    public function test_marketer_cannot_enrich_or_reshoot_live_site(): void
    {
        Bus::fake();

        $site = $this->makeSite([
            'site_name' => 'Live Enrich Block',
            'site_url' => 'https://live-enrich-block.example',
            'domain' => 'live-enrich-block.example',
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.enrich', $site->id))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.refresh-screenshot', $site->id))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.refresh-metrics', $site->id))
            ->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_sites_index_does_not_embed_site_rows_for_publishers(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        // Publisher list should render; site URLs are loaded via AJAX only.
        $this->assertStringContainsString((string) $this->publisher->email, $html);
        $this->assertStringNotContainsString('mkt-preview.example', $html);
    }

    public function test_marketing_sites_page_wires_preview_fallback_and_hover_zoom(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function sitePreviewPaths', $html);
        $this->assertStringContainsString('function siteMediaUrl', $html);
        $this->assertStringContainsString('preview_thumb_url', $html);
        $this->assertStringContainsString('preview_fallback_urls', $html);
        $this->assertStringContainsString('sitePreviewImgOnError', $html);
        $this->assertStringContainsString('initSitePreviewZoom', $html);
        $this->assertStringContainsString('site-preview-zoom-pop', $html);
        $this->assertStringContainsString('hydrateSiteDetailImages', $html);
        $this->assertStringContainsString('data-detail-src', $html);
        $this->assertStringContainsString('sync: false', $html);

        // Preview sizing lives in the shared staff stylesheet (not inline HTML).
        $this->assertStringContainsString('staff-sites.css', $html);
        $staffCss = (string) file_get_contents(public_path('assets/css/staff-sites.css'));
        $this->assertStringContainsString('.site-row-preview', $staffCss);
        $this->assertStringContainsString('padding-top: 62.5%', $staffCss);
        $this->assertStringContainsString('.site-image-desktop-preview', $staffCss);
        $this->assertStringContainsString('object-fit: contain', $staffCss);
        // Absolute <img> needs the ::before padding frame — do not strip it via @supports.
        $this->assertStringNotContainsString('@supports (aspect-ratio: 16 / 10)', $staffCss);
        $this->assertStringContainsString('min-height: 180px', $staffCss);

        $css = (string) file_get_contents(public_path('assets/css/admin-tables.css'));
        $this->assertStringContainsString('min-width: 168px', $css);
        $this->assertStringNotContainsString('width: min(120px, 100%)', $css);

        $staffCss = (string) file_get_contents(public_path('assets/css/staff-sites.css'));
        $this->assertMatchesRegularExpression(
            '/\.site-row-preview img\s*\{[^}]*object-fit:\s*contain/s',
            $staffCss
        );
        $this->assertStringContainsString('.site-preview-zoom-pop', $staffCss);
        $this->assertStringContainsString('.site-image-desktop-preview.is-zooming img', $staffCss);
        $this->assertStringContainsString('@media (any-hover: hover)', $staffCss);
    }
}
