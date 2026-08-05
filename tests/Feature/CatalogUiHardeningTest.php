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

    public function test_buy_button_price_updates_apply_the_active_discount(): void
    {
        $js = $this->catalogJs();

        // Selecting a sensitive topic used to add the add-on onto the list
        // price and drop the sale when returning to "no sensitive topic".
        $this->assertStringContainsString('function catalogApplyDiscount(', $js);
        $this->assertStringContainsString('catalogApplyDiscount(listTotal, pct)', $js);
        $this->assertStringContainsString('data-discount-percent', $this->catalogBlade());
        $this->assertStringContainsString('list-price-display', $this->catalogBlade());
    }

    public function test_filter_tags_are_built_without_an_inline_handler(): void
    {
        $js = $this->catalogJs();

        // A label containing an apostrophe used to break out of the onclick.
        $this->assertStringNotContainsString('onclick="event.stopPropagation(); removeMultiFilter(', $js);
        $this->assertStringContainsString("createElement('button')", $js);
        $this->assertStringContainsString('data-filter-type', $js);
        $this->assertStringContainsString('.remove-tag[data-filter-type]', $js);

        // Capture phase, or .multi-select-input's own onclick opens the dropdown
        // first and the × reads as unclickable.
        $this->assertMatchesRegularExpression(
            '/addEventListener\(\s*[\'"]click[\'"]\s*,\s*function\s*\([^)]*\)\s*\{[\s\S]*?remove-tag\[data-filter-type\][\s\S]*?\}\s*,\s*true\s*\)/',
            $js
        );
    }

    public function test_selected_filter_tags_target_the_ids_that_exist(): void
    {
        $js = $this->catalogJs();
        $blade = $this->catalogBlade();

        // Deriving the id by appending "s" gave selectedCategorysDisplay and
        // selectedCountrysDisplay, so those two filters never rendered a tag.
        $this->assertStringNotContainsString("'selected' + type.charAt(0).toUpperCase()", $js);
        $this->assertStringContainsString('MULTI_FILTER_UI', $js);

        foreach (['selectedCategoriesDisplay', 'selectedCountriesDisplay', 'selectedLanguagesDisplay'] as $id) {
            $this->assertStringContainsString($id, $js, 'catalog.js should target '.$id);
            $this->assertStringContainsString('id="'.$id.'"', $blade, 'markup should define '.$id);
        }
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

        foreach (['.category-tile', '.btn-toggle-categories', '.categories-grid', '.favorite-btn.btn-danger'] as $dead) {
            $this->assertStringNotContainsString($dead, $css, $dead.' is unused in the catalog markup');
        }

        // .pulse-dot was display:none and its keyframes ran on nothing; browsers
        // ignore nearly all <option> styling, so 70 lines of it did nothing.
        $this->assertStringNotContainsString('.pulse-dot', $css);
        $this->assertStringNotContainsString('select.form-select option', $css);

        // .catalog-table-scroll and the chevron rule were each declared twice.
        $this->assertSame(1, substr_count($css, '.catalog-table-scroll {'));
    }

    public function test_the_shared_multi_select_owns_the_filter_dropdown(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $shared = (string) file_get_contents(public_path('assets/css/multi-select.css'));

        // catalog.css redeclared the whole widget, so catalog filters looked
        // different from every other multi-select — and its z-index of 1000 put
        // the open dropdown underneath the topbar.
        // Scoped tweaks such as ".catalog-filters-card .multi-select-input" are
        // fine; redeclaring the widget itself is what caused the drift.
        foreach (['.multi-select-dropdown', '.selected-tag', '.option-item', '.multi-select-input'] as $owned) {
            $this->assertDoesNotMatchRegularExpression(
                '/^'.preg_quote($owned, '/').'[\s,{]/m',
                $css,
                'multi-select.css owns '.$owned
            );
            $this->assertStringContainsString($owned, $shared);
        }

        $this->assertStringContainsString('z-index: var(--shell-z-dropdown', $shared);

        // The remove affordance is a real button here so it is keyboard
        // reachable; the shared sheet has to strip the native chrome.
        $this->assertStringContainsString('button.remove-tag', $shared);
    }

    public function test_page_styles_do_not_leak_into_the_shell(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $blade = $this->catalogBlade();

        $this->assertStringContainsString('container-fluid catalog-page', $blade);

        // Bare .table / .badge / .form-control reached the cart drawer and the
        // nav, because this sheet loads after the shell's own stylesheets.
        foreach ([
            '.catalog-page .table {',
            '.catalog-page .badge {',
            '.catalog-page .btn-link {',
            '.catalog-page .form-control-sm,',
        ] as $scoped) {
            $this->assertStringContainsString($scoped, $css);
        }

        $this->assertDoesNotMatchRegularExpression('/^\.table\s*\{/m', $css);
        $this->assertDoesNotMatchRegularExpression('/^\.badge\s*\{/m', $css);
        $this->assertDoesNotMatchRegularExpression('/^thead th\s*\{/m', $css);
    }

    public function test_the_page_stylesheet_loads_in_the_head(): void
    {
        $blade = $this->catalogBlade();
        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));

        // Loading it with the body painted the page unstyled first.
        $this->assertStringContainsString("@push('page-styles')", $blade);
        $this->assertStringContainsString("@stack('page-styles')", $layout);

        // And it has to sit before the hover system so that sheet still wins.
        $this->assertLessThan(
            strpos($layout, 'hover-system.css'),
            strpos($layout, "@stack('page-styles')"),
            'page styles must load before the hover system'
        );
    }

    public function test_the_table_only_appears_where_its_columns_fit(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // The table needs ~995px of columns. At md the sidebar leaves far less,
        // so Action — the column holding Buy — sat off the right edge.
        $this->assertStringContainsString('catalog-table-scroll d-none d-xl-block', $html);
        $this->assertStringContainsString('catalog-mobile-list d-xl-none', $html);
        $this->assertStringNotContainsString('catalog-table-scroll d-none d-md-block', $html);

        // Pixel floors on two columns forced the scrollbar before content did.
        $this->assertStringNotContainsString('style="min-width: 250px;"', $html);
        $this->assertStringNotContainsString('style="min-width: 180px;"', $html);
    }

    public function test_the_pinned_action_column_follows_the_row_it_belongs_to(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        // A hard white background left a pale seam beside blacklisted rows and
        // swallowed the hover wash on the one column that always stays put.
        $this->assertMatchesRegularExpression(
            '/\.catalog-th-action,\s*\n\.catalog-td-action \{[^}]*background-color: inherit/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/tr\.site-row \{[^}]*background-color: var\(--surface-1/',
            $css
        );
    }

    public function test_cards_carry_the_details_the_table_keeps_in_its_expand_row(): void
    {
        $site = $this->makeSite([
            'description' => 'A short description that only the table used to show.',
            'publication_time' => '12 months',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="card-details-'.$site->id.'"', $html);
        $this->assertStringContainsString('aria-controls="card-details-'.$site->id.'"', $html);
        $this->assertStringContainsString('A short description that only the table used to show.', $html);
        $this->assertStringContainsString('12 months', $html);

        // One control for the address, and it can put it back. The card used to
        // show a reveal button and a toggle button for the same job.
        $cards = substr($html, (int) strpos($html, 'catalog-mobile-list'));
        $cards = substr($cards, 0, (int) strpos($cards, 'window.CatalogConfig'));
        $this->assertSame(0, substr_count($cards, 'reveal-url btn-icon-quiet'));
        $this->assertSame(1, substr_count($cards, 'toggle-url btn-icon-quiet'));
    }

    public function test_the_more_filters_drawer_fits_twelve_columns(): void
    {
        $blade = $this->catalogBlade();

        $drawer = substr($blade, (int) strpos($blade, '<!-- More filters drawer -->'));
        $drawer = substr($drawer, 0, (int) strpos($drawer, '</form>'));

        // Seven fields at col-md-2/3 summed to 15, so three of them wrapped and
        // the drawer looked misaligned at every desktop width.
        $this->assertSame(0, substr_count($drawer, 'class="col-md-2"'));
        $this->assertSame(0, substr_count($drawer, 'class="col-md-3"'));
        $this->assertSame(7, substr_count($drawer, 'class="col-6 col-md-4 col-lg-3"'));
    }

    public function test_hiding_the_filters_survives_a_submit(): void
    {
        $this->makeSite();

        $collapsed = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'catalog', 'filters_open' => 0]))
            ->assertOk()
            ->getContent();

        // The form used to post filters_open=1 unconditionally, so sorting or
        // reloading re-opened a panel the shopper had just closed.
        $this->assertStringContainsString('id="catalogFiltersPanel"', $collapsed);
        $this->assertMatchesRegularExpression('/id="catalogFiltersPanel"[^>]*/', $collapsed);
        $this->assertStringContainsString('value="0"', $collapsed);
        $this->assertStringContainsString('>Show filters<', $collapsed);

        $expanded = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'catalog']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Hide filters<', $expanded);
    }

    public function test_every_filter_submit_syncs_the_multi_select_fields(): void
    {
        $js = $this->catalogJs();
        $blade = $this->catalogBlade();

        // Sorting called form.submit() directly, which posted the hidden fields
        // as rendered and dropped any tag ticked since page load.
        $this->assertStringNotContainsString("onchange=\"document.getElementById('filterForm').submit()\"", $blade);
        $this->assertStringContainsString('function syncCatalogFilterFields', $js);
        $this->assertStringContainsString("form.addEventListener('submit'", $js);
        $this->assertStringContainsString("sort.addEventListener('change'", $js);
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
