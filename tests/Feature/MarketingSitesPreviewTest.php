<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_user_sites_json_prefers_existing_site_image_over_missing_screenshot(): void
    {
        Storage::disk('public')->put('sites/cover-real.webp', 'fake-image-bytes');

        $site = $this->makeSite([
            // Stale capture path that is not on disk anymore.
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
        $this->assertNotEmpty($row['preview_thumb_url']);
        $this->assertStringContainsString('sites/cover-real.webp', $row['preview_thumb_url']);
        $this->assertStringContainsString('sites/cover-real.webp', $row['preview_full_url']);
        $this->assertContains(
            $row['preview_thumb_url'],
            $row['preview_fallback_urls']
        );
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
        // Row + zoom both use the full desktop capture (not the tight thumb crop).
        $this->assertStringContainsString('site-screenshots/home-full.webp', $row['preview_thumb_url']);
        $this->assertStringContainsString('site-screenshots/home-full.webp', $row['preview_full_url']);
        $this->assertSame($row['preview_thumb_url'], $row['preview_full_url']);
    }

    public function test_marketing_sites_page_wires_preview_fallback_and_hover_zoom(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function sitePreviewPaths', $html);
        $this->assertStringContainsString('preview_thumb_url', $html);
        $this->assertStringContainsString('preview_fallback_urls', $html);
        $this->assertStringContainsString('sitePreviewImgOnError', $html);
        $this->assertStringContainsString('initSitePreviewZoom', $html);
        $this->assertStringContainsString('site-preview-zoom-pop', $html);
        $this->assertStringContainsString('object-fit: contain', $html);
        $this->assertStringNotContainsString(
            '.site-row-preview img {\n    width: 100%;\n    height: 100%;\n    object-fit: cover;',
            $html
        );

        $css = (string) file_get_contents(public_path('assets/css/admin-tables.css'));
        $this->assertStringContainsString('min-width: 136px', $css);
        $this->assertStringNotContainsString('width: min(120px, 100%)', $css);
    }
}
