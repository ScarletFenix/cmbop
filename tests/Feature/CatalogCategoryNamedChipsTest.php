<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogUrlQuery;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — one Active filter chip per selected niche (clear removes that niche only).
 */
class CatalogCategoryNamedChipsTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);
        Category::flushNicheLookupCache();

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

    private function site(): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Named Chip Site',
            'site_url' => 'https://named-chip.example',
            'domain' => 'named-chip.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'Marketing, PR & Advertising',
            'categories' => ['Marketing, PR & Advertising', 'Health & Wellness'],
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Named chip fixture.',
            'verified' => true,
            'active' => 1,
        ]);
    }

    public function test_active_filters_show_one_named_chip_per_niche_not_opaque_category(): void
    {
        $this->site();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Health & Wellness|Marketing, PR & Advertising',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="Remove filter: Health &amp; Wellness"', $html);
        $this->assertStringContainsString('aria-label="Remove filter: Marketing, PR &amp; Advertising"', $html);
        $this->assertStringNotContainsString('aria-label="Remove filter: Category"', $html);
        $this->assertStringNotContainsString(">Category\n", $html);
    }

    public function test_removing_one_niche_chip_keeps_the_other_in_the_url(): void
    {
        $this->site();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Health & Wellness|Marketing, PR & Advertising',
                'da_min' => 20,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match(
            '/href="([^"]+)"[^>]*class="filter-chip__remove"[^>]*aria-label="Remove filter: Marketing, PR &amp; Advertising"/',
            $html,
            $matches
        ));

        $removeMarketing = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = urldecode($removeMarketing);

        $this->assertStringContainsString('Health', $decoded);
        $this->assertStringContainsString('Wellness', $decoded);
        $this->assertStringContainsString('da_min=20', $decoded);
        $this->assertStringNotContainsString('Marketing, PR', $decoded);
        $this->assertStringNotContainsString('Advertising', $decoded);
    }

    public function test_without_category_niche_helper_rewrites_pipe_param(): void
    {
        $next = CatalogUrlQuery::withoutCategoryNiche([
            'category' => 'Health & Wellness|Marketing, PR & Advertising',
            'da_min' => '30',
            'page' => '2',
        ], 'Marketing, PR & Advertising');

        $this->assertSame('Health & Wellness', $next['category']);
        $this->assertSame('30', $next['da_min']);
        $this->assertArrayNotHasKey('page', $next);
    }

    public function test_removing_last_niche_drops_category_param(): void
    {
        $next = CatalogUrlQuery::withoutCategoryNiche([
            'category' => 'Marketing, PR & Advertising',
            'search' => 'guest',
        ], 'Marketing, PR & Advertising');

        $this->assertArrayNotHasKey('category', $next);
        $this->assertSame('guest', $next['search']);
    }

    public function test_legacy_comma_url_still_renders_named_chips(): void
    {
        $this->site();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Health & Wellness,Marketing, PR & Advertising',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="Remove filter: Health &amp; Wellness"', $html);
        $this->assertStringContainsString('aria-label="Remove filter: Marketing, PR &amp; Advertising"', $html);
    }

    public function test_live_chip_js_rebuilds_category_without_removed_niche(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('categoryRemove', $js);
        $this->assertStringContainsString('paramsWithoutCategoryNiche', $js);
        $this->assertStringNotContainsString(
            "chips.push({ label: 'Category', params: ['category'] });",
            $js
        );
    }
}
