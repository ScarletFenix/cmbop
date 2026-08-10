<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\SiteUrlVisibility;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catalog shows the listing name, then the rooted URL (https://host) under it
 * in muted type — never deep paths like /blog.
 */
class CatalogRootedUrlDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

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
            'site_name' => 'Brand Magazine Weekly',
            'site_url' => 'https://news.brandmagazine.example/blog/guest-post',
            'domain' => 'news.brandmagazine.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Catalog rooted URL display fixture.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    public function test_catalog_shows_site_name_above_rooted_url_for_normal_advertisers(): void
    {
        $this->makeSite();

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-site-name', $html);
        $this->assertStringContainsString('Brand Magazine Weekly', $html);
        $this->assertStringContainsString('catalog-site-rooted-url', $html);
        $this->assertMatchesRegularExpression(
            '/catalog-site-rooted-url[^>]*>\s*https:\/\/news\.brandmagazine\.example\s*</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/catalog-site-rooted-url[^>]*>\s*https:\/\/news\.brandmagazine\.example\/blog/',
            $html
        );
    }

    public function test_catalog_masks_rooted_url_in_hide_mode_until_revealed(): void
    {
        $this->advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        $this->makeSite();

        $html = (string) $this->actingAs($this->advertiser->fresh())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $maskedName = app(SiteUrlVisibility::class)->maskName('Brand Magazine Weekly');
        $this->assertStringContainsString($maskedName, $html);
        $this->assertStringNotContainsString('Brand Magazine Weekly', $html);
        $this->assertStringContainsString('catalog-site-rooted-url', $html);
        $this->assertStringNotContainsString('news.brandmagazine.example', $html);
        $this->assertStringNotContainsString('/blog/guest-post', $html);
        $this->assertMatchesRegularExpression('#https://[^"\'<\s]*\*\*\*\.example#', $html);
    }

    public function test_reveal_paint_helper_formats_rooted_https_display(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function formatRootedDisplay(', $js);
        $this->assertStringContainsString('function paintHostElements(', $js);
        $this->assertStringContainsString("'https://'", $js);
    }
}
