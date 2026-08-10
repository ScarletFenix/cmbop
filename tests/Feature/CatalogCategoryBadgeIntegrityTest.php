<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 — catalog row badge integrity for niches that contain commas.
 */
class CatalogCategoryBadgeIntegrityTest extends TestCase
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

    private function site(string $name, string $domain, ?array $categories, ?string $legacyCategory): Site
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
            'category' => $legacyCategory ?? ($categories[0] ?? 'Business & Finance'),
            'categories' => $categories,
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Badge integrity fixture.',
            'verified' => true,
            'active' => 1,
        ]);
    }

    public function test_json_categories_keep_each_comma_niche_as_one_badge(): void
    {
        foreach (Category::NICHES_CONTAINING_COMMA as $i => $niche) {
            $this->site('Badge '.$i, 'badge-'.$i.'.example', [$niche], $niche);
        }

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        foreach (Category::NICHES_CONTAINING_COMMA as $niche) {
            $escaped = e($niche);
            $this->assertMatchesRegularExpression(
                '/category-badge[^>]*>\s*'.preg_quote($escaped, '/').'\s*</',
                $html,
                "Expected one badge for {$niche}"
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/category-badge[^>]*>\s*Marketing\s*</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/category-badge[^>]*>\s*PR &amp; Advertising\s*</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/category-badge[^>]*>\s*Events\s*</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/category-badge[^>]*>\s*NGOs\s*</',
            $html
        );
    }

    public function test_legacy_category_string_alone_stays_one_badge(): void
    {
        $this->site(
            'Legacy Only Comma Niche',
            'legacy-only-comma.example',
            null,
            'Marketing, PR & Advertising'
        );

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/category-badge[^>]*>\s*Marketing, PR &amp; Advertising\s*</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/category-badge[^>]*>\s*Marketing\s*</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/category-badge[^>]*>\s*PR &amp; Advertising\s*</',
            $html
        );
    }

    public function test_multi_niche_row_shows_comma_niche_plus_sibling_as_separate_pills(): void
    {
        $this->site(
            'Multi Niche Row',
            'multi-niche-badges.example',
            ['Health & Wellness', 'Marketing, PR & Advertising'],
            'Health & Wellness'
        );

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/category-badge[^>]*>\s*Health &amp; Wellness\s*</',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/category-badge[^>]*>\s*Marketing, PR &amp; Advertising\s*</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/category-badge[^>]*>\s*Marketing\s*</',
            $html
        );
        $this->assertGreaterThanOrEqual(2, preg_match_all('/category-badge/', $html));
    }

    public function test_results_blade_does_not_explode_category_names_on_commas(): void
    {
        $blade = (string) file_get_contents(
            resource_path('views/advertiser/partials/catalog-results.blade.php')
        );

        $this->assertStringContainsString('nicheBadgeLabels()', $blade);
        $this->assertStringNotContainsString("explode(',', \$cat)", $blade);
        $this->assertStringNotContainsString("explode(',', \$site->category)", $blade);
        $this->assertStringNotContainsString('str_contains($cat, \',\')', $blade);
        $this->assertStringNotContainsString('str_contains($site->category, \',\')', $blade);
    }
}
