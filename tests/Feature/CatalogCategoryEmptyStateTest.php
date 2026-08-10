<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogFilterStatus;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Phase 6 — category-aware empty state copy + related niche recovery.
 */
class CatalogCategoryEmptyStateTest extends TestCase
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

    private function site(string $name, string $domain, array $categories): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 50,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => $categories[0],
            'categories' => $categories,
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Category empty-state fixture.',
            'verified' => true,
            'active' => 1,
        ]);
    }

    public function test_empty_category_filter_shows_named_headline_and_clear_category(): void
    {
        // Sibling niche has inventory; filtered niche does not.
        $this->site('Health Inventory', 'health-inv.example', ['Health & Wellness']);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Marketing, PR & Advertising',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No sites in Marketing, PR &amp; Advertising', $html);
        $this->assertStringContainsString('data-status-text="No sites in Marketing, PR &amp; Advertising"', $html);
        $this->assertStringContainsString('catalog-clear-category', $html);
        $this->assertStringContainsString('Clear category', $html);
        $this->assertStringContainsString('Clear this category or try a related niche', $html);
        $this->assertStringNotContainsString('No sites match these filters', $html);
    }

    public function test_empty_category_suggests_related_niches_with_inventory(): void
    {
        $this->site('Health Inventory', 'health-related.example', ['Health & Wellness']);
        $this->site('Medical Inventory', 'medical-related.example', ['Medical & Clinics']);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Pharma & Supplements',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Related niches:', $html);
        $this->assertStringContainsString('catalog-related-niche', $html);
        $this->assertStringContainsString('Health &amp; Wellness', $html);
    }

    public function test_related_niches_count_case_insensitive_json_and_legacy_category(): void
    {
        // JSON casing differs from canonical; legacy category alone also counts.
        $this->site('Lower Health Inv', 'lower-health-inv.example', ['health & wellness']);
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Legacy Medical Only',
            'site_url' => 'https://legacy-medical.example',
            'domain' => 'legacy-medical.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'Medical & Clinics',
            'categories' => ['Other'],
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Legacy category inventory fixture.',
            'verified' => true,
            'active' => 1,
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Pharma & Supplements',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Related niches:', $html);
        $this->assertStringContainsString('Health &amp; Wellness', $html);
        $this->assertStringContainsString('Medical &amp; Clinics', $html);
    }

    public function test_clear_category_url_keeps_other_filters(): void
    {
        $status = app(CatalogFilterStatus::class);
        $request = Request::create('/advertiser/catalog', 'GET', [
            'category' => 'Marketing, PR & Advertising',
            'search' => 'guest',
            'da_min' => 20,
            'page' => 2,
        ]);

        $recovery = $status->emptyRecovery($request);
        $this->assertNotNull($recovery['clear_category_url']);
        $this->assertStringContainsString('search=guest', $recovery['clear_category_url']);
        $this->assertStringContainsString('da_min=20', $recovery['clear_category_url']);
        $this->assertStringNotContainsString('category=', $recovery['clear_category_url']);
        $this->assertStringNotContainsString('page=', $recovery['clear_category_url']);
        $this->assertStringContainsString('guest', $recovery['body']);
        $this->assertStringContainsString('Marketing, PR & Advertising', $recovery['body']);
    }

    public function test_multi_niche_empty_lists_both_names(): void
    {
        $summary = app(CatalogFilterStatus::class)->summarize(
            Request::create('/advertiser/catalog', 'GET', [
                'category' => 'Health & Wellness|Marketing, PR & Advertising',
            ]),
            0
        );

        $this->assertSame(
            'No sites in Health & Wellness or Marketing, PR & Advertising',
            $summary['text']
        );
    }

    public function test_comma_niche_empty_stays_one_name_in_headline(): void
    {
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Events, Conferences & Trade Fairs',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'No sites in Events, Conferences &amp; Trade Fairs',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/No sites in Events<(?![\s\S]*Conferences)/',
            $html
        );
        $this->assertStringNotContainsString('aria-label="Remove filter: Events"', $html);
    }

    public function test_live_results_fragment_exposes_status_copy_for_js_sync(): void
    {
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.results', [
                'category' => 'Marketing, PR & Advertising',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="catalogResults"[^>]*data-status-text="No sites in Marketing, PR &amp; Advertising"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-status-announce="No sites in Marketing, PR &amp; Advertising"/',
            $html
        );

        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString("card.getAttribute('data-status-text')", $js);
        $this->assertStringContainsString("card.getAttribute('data-status-announce')", $js);
    }
}
