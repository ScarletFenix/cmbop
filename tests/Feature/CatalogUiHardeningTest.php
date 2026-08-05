<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogUiHardeningTest extends TestCase
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
            'site_name' => 'Catalog Site',
            'site_url' => 'https://catalog-site.example',
            'domain' => 'catalog-site.example',
            'example_url' => 'https://catalog-site.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 9000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'A catalog listing used for UI hardening tests.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    private function catalogJs(): string
    {
        return (string) file_get_contents(public_path('assets/js/catalog.js'));
    }

    private function catalogBlade(): string
    {
        return (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));
    }

    public function test_the_url_guard_rejects_dangerous_schemes(): void
    {
        $this->assertSame('https://example.com/a', safe_external_url('https://example.com/a'));
        $this->assertSame('/storage/a.png', safe_external_url('/storage/a.png'));

        foreach ([
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            "java\0script:alert(1)",
            'data:text/html,<script>alert(1)</script>',
            '//evil.example.com',
            'vbscript:msgbox(1)',
            '',
            null,
        ] as $unsafe) {
            $this->assertSame('#', safe_external_url($unsafe), 'Should reject: '.var_export($unsafe, true));
        }
    }

    public function test_a_hostile_sample_url_is_never_put_in_an_href(): void
    {
        $site = $this->makeSite(['example_url' => 'javascript:alert(document.cookie)']);

        // The advertiser must be able to see the URL for the block to render.
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString("href='javascript:", $html);
        unset($site);
    }

    public function test_catalog_js_escapes_publisher_supplied_values(): void
    {
        $js = $this->catalogJs();

        $this->assertStringContainsString('function catalogEscapeHtml(', $js);
        // The sensitive-topic key is publisher-defined and goes into innerHTML.
        $this->assertStringContainsString('catalogEscapeHtml(selected.type)', $js);
        $this->assertStringNotContainsString("'<strong>' + selected.type", $js);
    }

    public function test_filter_tags_are_built_without_an_inline_handler(): void
    {
        $js = $this->catalogJs();

        // A label containing an apostrophe used to break out of the onclick.
        $this->assertStringNotContainsString('onclick="event.stopPropagation(); removeMultiFilter(', $js);
        $this->assertStringContainsString("createElement('button')", $js);
        $this->assertStringContainsString('data-filter-type', $js);
        $this->assertStringContainsString('.remove-tag[data-filter-type]', $js);
    }

    public function test_optimistic_favourite_and_blacklist_changes_revert_on_failure(): void
    {
        $js = $this->catalogJs();

        // Snapshot taken before the optimistic update, restored if the save fails.
        $this->assertStringContainsString('previousFavorites', $js);
        $this->assertStringContainsString('previousBlacklist', $js);
        $this->assertStringContainsString('favorites = previousFavorites;', $js);
        $this->assertStringContainsString('blacklist = previousBlacklist;', $js);

        // Both savers must resolve to a boolean so the caller can roll back.
        $this->assertStringContainsString('saveFavorites().then(function (ok) {', $js);
        $this->assertStringContainsString('saveBlacklist().then(function (ok) {', $js);
        foreach (['saveFavorites', 'saveBlacklist'] as $fn) {
            $body = substr($js, (int) strpos($js, 'function '.$fn.'('));
            $body = substr($body, 0, (int) strpos($body, "\n}\n"));
            $this->assertStringContainsString('return true;', $body, $fn.' should report success');
            $this->assertStringContainsString('return false;', $body, $fn.' should report failure');
        }
    }

    public function test_each_active_filter_can_be_removed_on_its_own(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'catalog', 'da_min' => 30, 'da_max' => 60]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('filter-chip__remove', $html);
        $this->assertStringContainsString('aria-label="Remove filter: Search: catalog"', $html);
        $this->assertStringContainsString('aria-label="Remove filter: DA (Domain Authority)"', $html);
        // Clearing one chip keeps the others, unlike the old clear-all-only link.
        $this->assertStringContainsString('da_min=30', $html);
        $this->assertStringContainsString('Clear all', $html);
    }

    public function test_removing_a_range_chip_clears_both_ends(): void
    {
        $blade = $this->catalogBlade();

        $this->assertStringContainsString("'params' => ['da_min', 'da_max']", $blade);
        $this->assertStringContainsString("'params' => ['price_min', 'price_max']", $blade);
        $this->assertStringContainsString("'params' => ['traffic_min', 'traffic_max']", $blade);
        // Page must reset, or a narrower result set can land on an empty page.
        $this->assertStringContainsString("array_merge(\$chip['params'], ['page'])", $blade);
    }

    public function test_table_and_range_inputs_are_described_for_screen_readers(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Seven columns, each labelling its own column.
        $this->assertSame(7, substr_count($html, 'scope="col"'));

        // Eight range inputs all used to announce as "Min" or "Max".
        foreach ([
            'Minimum price in euros',
            'Maximum price in euros',
            'Minimum Domain Authority',
            'Maximum Domain Authority',
            'Minimum Domain Rating',
            'Maximum Domain Rating',
            'Minimum monthly traffic',
            'Maximum monthly traffic',
        ] as $label) {
            $this->assertStringContainsString('aria-label="'.$label.'"', $html);
        }
    }

    public function test_the_expand_control_points_at_the_panel_it_opens(): void
    {
        $site = $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-controls="site-details-'.$site->id.'"', $html);
        $this->assertStringContainsString('id="site-details-'.$site->id.'"', $html);
        $this->assertStringContainsString('aria-label="Show details for '.$site->site_name.'"', $html);
    }

    public function test_row_chrome_moved_out_of_inline_styles(): void
    {
        $blade = $this->catalogBlade();
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertStringNotContainsString('opacity: 0.7; background-color: #fff3f3;', $blade);
        $this->assertStringContainsString('.blacklisted-row', $css);
        $this->assertStringContainsString('.catalog-expand-cell', $css);
        $this->assertStringContainsString('catalog-expand-cell', $blade);
    }

    public function test_duplicated_and_dead_catalog_css_is_gone(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        // Both blocks were declared twice, the second winning with !important.
        $this->assertSame(1, substr_count($css, '.selected-tag {'));
        $this->assertSame(1, substr_count($css, '.option-item {'));

        foreach (['.category-tile', '.btn-toggle-categories', '.categories-grid', '.favorite-btn.btn-danger'] as $dead) {
            $this->assertStringNotContainsString($dead, $css, $dead.' is unused in the catalog markup');
        }
    }

    public function test_the_stale_duplicate_catalog_script_is_removed(): void
    {
        // public/js/catalog.js was 319 lines behind and loaded by nothing, so
        // editing it looked like a no-op.
        $this->assertFileDoesNotExist(public_path('js/catalog.js'));
        $this->assertFileExists(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('assets/js/catalog.js', $this->catalogBlade());
    }
}
