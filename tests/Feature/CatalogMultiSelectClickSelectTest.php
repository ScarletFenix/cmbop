<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogMultiSelectClickSelectTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'advertiser'],
            ['guard_name' => 'web']
        );

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function publisher(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'publisher'],
            ['guard_name' => 'web']
        );

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        // Phase 5 — niche picker options come from `categories`, not a hardcoded list.
        $this->seed(CategoriesTableSeeder::class);
        Category::flushNicheLookupCache();
        Cache::flush();
    }

    public function test_catalog_multi_select_markup_supports_click_select_without_visible_checks(): void
    {
        $publisher = $this->publisher();

        Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Click Select DE',
            'site_url' => 'https://click-select-de.test',
            'domain' => 'click-select-de.test',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Fixture for click-select multi filters.',
            'verified' => true,
            'active' => true,
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        foreach (['category', 'country', 'language'] as $type) {
            $this->assertStringContainsString('data-multi-select="'.$type.'"', $html);
        }

        // Phase 4 — count copy comes from markup data attrs (JS reads dataset first).
        $this->assertStringContainsString('id="selectedCategoriesDisplay" data-placeholder="All categories" data-singular="category" data-plural="categories"', $html);
        $this->assertStringContainsString('id="selectedCountriesDisplay" data-placeholder="All countries" data-singular="country" data-plural="countries"', $html);
        $this->assertStringContainsString('id="selectedLanguagesDisplay" data-placeholder="All languages" data-singular="language" data-plural="languages"', $html);

        $this->assertStringContainsString('role="option"', $html);
        $this->assertStringContainsString('aria-selected="false"', $html);
        $this->assertStringContainsString('aria-multiselectable="true"', $html);
        // Phase 6 — option rows are programmatically focusable for Arrow/Enter/Space.
        $this->assertMatchesRegularExpression(
            '/class="option-item"[^>]*role="option"[^>]*tabindex="-1"/',
            $html
        );
        // Checkboxes remain for state; CSS hides them for catalog wrappers only.
        $this->assertStringContainsString('type="checkbox"', $html);
        // Phase 7 — each picker still ships real option inputs (visually hidden).
        foreach (['category', 'country', 'language'] as $type) {
            $this->assertMatchesRegularExpression(
                '/id="'.$type.'MultiOptions"[\s\S]*?<input[^>]*type="checkbox"[^>]*data-type="'.$type.'"/',
                $html,
                $type.' options must keep checkbox inputs for state/a11y'
            );
        }
        // Hidden fields still post the filter query params.
        $this->assertMatchesRegularExpression('/name="category"[^>]*id="selectedCategory"|id="selectedCategory"[^>]*name="category"/', $html);
        $this->assertMatchesRegularExpression('/name="country"[^>]*id="selectedCountry"|id="selectedCountry"[^>]*name="country"/', $html);
        $this->assertMatchesRegularExpression('/name="language"[^>]*id="selectedLanguage"|id="selectedLanguage"[^>]*name="language"/', $html);
        // Country sections/helpers unchanged (no structural rewrite).
        $this->assertStringContainsString('data-country-group="dach_plus"', $html);
        $this->assertStringContainsString('data-section="recent"', $html);
    }

    public function test_click_select_css_hides_catalog_checkboxes_and_styles_selected_rows(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/multi-select.css'));

        $this->assertStringContainsString(
            '.multi-select-wrapper[data-multi-select] .option-item input[type="checkbox"]',
            $css
        );
        $this->assertStringContainsString('clip: rect(0, 0, 0, 0)', $css);
        $this->assertStringContainsString('opacity: 0', $css);
        $this->assertStringContainsString('.option-item.is-selected', $css);
        $this->assertStringContainsString(':has(input:checked)', $css);
        $this->assertStringContainsString('.option-item.is-keyboard-focus', $css);
        $this->assertStringContainsString('.selected-items.is-compact', $css);
        $this->assertStringContainsString('selected-tag--count', $css);
        // Full-row hit target for click-to-select labels.
        $this->assertStringContainsString('width: 100%', $css);
        $this->assertMatchesRegularExpression(
            '/\[data-multi-select\][^{]*\.option-item[^{]*\{[^}]*width:\s*100%/s',
            $css
        );
    }

    public function test_click_select_js_syncs_highlight_and_named_tags(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function syncOptionSelectedState(type)', $js);
        $this->assertStringContainsString('function multiDisplayOverflows(container)', $js);
        $this->assertStringContainsString('function shouldCompactMultiDisplay(values)', $js);
        $this->assertStringContainsString('function clearMultiFilter(type)', $js);
        $this->assertStringContainsString("aria-selected', on ? 'true' : 'false'", $js);
        $this->assertStringContainsString('No visible checkboxes', $js);
        $this->assertStringContainsString("classList.toggle('is-selected', on)", $js);

        // Phase 0/1 — always named tags (compact disabled).
        $this->assertStringContainsString("singular: 'country'", $js);
        $this->assertStringContainsString("plural: 'countries'", $js);
        $this->assertStringContainsString("singular: 'category'", $js);
        $this->assertStringContainsString("plural: 'categories'", $js);
        $this->assertStringContainsString("singular: 'language'", $js);
        $this->assertStringContainsString("plural: 'languages'", $js);
        $this->assertMatchesRegularExpression(
            '/function shouldCompactMultiDisplay\(values\)\s*\{\s*return false;/s',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/function updateMultiDisplay\(type\)[\s\S]*?multiFilterOptionLabel\(type, value\)/',
            $js
        );
        // Phase 4 — count label prefers markup data-singular/data-plural (helpers kept).
        $this->assertStringContainsString('container.dataset.singular', $js);
        $this->assertStringContainsString('container.dataset.plural', $js);

        // Phase 2 call sites — reopen, update, remove/clear, init, DACH+/Nordics.
        $this->assertStringContainsString('Re-sync highlights so reopen always matches selectedMultiFilters', $js);
        $this->assertMatchesRegularExpression(
            '/function updateMultiFilter\(checkbox\)[\s\S]*?syncOptionSelectedState\(type\)/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/function removeMultiFilter\(type, value\)[\s\S]*?syncOptionSelectedState\(type\)/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/function clearMultiFilter\(type\)[\s\S]*?syncOptionSelectedState\(type\)/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/function initializeMultiSelects\(\)[\s\S]*?syncOptionSelectedState\(\'category\'\)[\s\S]*?syncOptionSelectedState\(\'country\'\)[\s\S]*?syncOptionSelectedState\(\'language\'\)/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/function selectGroup\(groupKey\)[\s\S]*?setActiveGroup\(groupKey\)[\s\S]*?refreshCountryPickerUi\(\)/',
            $js
        );
        $this->assertStringContainsString('selected-tag--group', $js);
        $this->assertStringContainsString('shouldCompactCountryDisplay', $js);
        $this->assertStringContainsString('groupContextForValues', $js);
        $this->assertStringContainsString('groupCodeSet', $js);
        $this->assertStringContainsString('setActiveGroup(groupKey)', $js);
        // Isolate selectGroup so later helpers cannot false-positive the “no write” checks.
        $this->assertMatchesRegularExpression(
            '/function selectGroup\(groupKey\) \{([\s\S]*?)\n    function bindGroupActions/',
            $js
        );
        preg_match(
            '/function selectGroup\(groupKey\) \{([\s\S]*?)\n    function bindGroupActions/',
            $js,
            $selectGroupMatch
        );
        $selectGroupBody = $selectGroupMatch[1] ?? '';
        $this->assertStringContainsString('setActiveGroup(groupKey)', $selectGroupBody);
        $this->assertStringContainsString('filterMultiOptions', $selectGroupBody);
        $this->assertStringNotContainsString('input.checked = true', $selectGroupBody);
        $this->assertStringNotContainsString('updateMultiFilter(', $selectGroupBody);
        $this->assertStringNotContainsString('selectedMultiFilters.country', $selectGroupBody);

        // Phase 5 — Recent + group helpers refresh highlights/display; Popular pins stay put.
        $this->assertStringContainsString('function refreshCountryPickerUi()', $js);
        $this->assertMatchesRegularExpression(
            '/function renderRecent\(\)[\s\S]*?data-section\'\) === \'popular\'[\s\S]*?continue;[\s\S]*?refreshCountryPickerUi\(\)/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/function rememberFromSelection\(codes\)[\s\S]*?refreshCountryPickerUi\(\)/',
            $js
        );
        $this->assertStringContainsString('Keep Popular pins where they are', $js);

        // Country Recent still pins on select via the shared checkbox path.
        $this->assertStringContainsString('CatalogCountryPicker.rememberFromSelection([value])', $js);
        $this->assertStringContainsString('CatalogCountryPicker.renderRecent()', $js);

        // Phase 6 — keyboard / a11y: Enter/Space toggles rows; search box ignored;
        // compact chip named; tag × capture-stops reopen.
        $this->assertStringContainsString("e.key === 'Enter' || e.key === ' '", $js);
        $this->assertStringContainsString("e.target.closest('.search-box')", $js);
        $this->assertStringContainsString("aria-label', label + ' selected'", $js);
        $this->assertStringContainsString('stopImmediatePropagation', $js);
        $this->assertMatchesRegularExpression(
            '/addEventListener\(\s*[\'"]click[\'"]\s*,\s*function\s*\([^)]*\)\s*\{[\s\S]*?remove-tag\[data-filter-type\][\s\S]*?stopImmediatePropagation[\s\S]*?\}\s*,\s*true\s*\)/',
            $js
        );
        $this->assertStringContainsString('focus the row (role=option)', $js);

        // Phase 7 — apply/filter query params still sync from multi-select state.
        $this->assertStringContainsString('function syncCatalogFilterFields', $js);
        $this->assertStringContainsString('selectedCategory: selectedMultiFilters.category', $js);
        $this->assertStringContainsString('selectedCountry: selectedMultiFilters.country', $js);
        $this->assertStringContainsString('selectedLanguage: selectedMultiFilters.language', $js);
        $this->assertStringContainsString('function formatMultiSelectTrigger(count, singular, plural)', $js);
        $this->assertStringContainsString('window.CatalogMultiSelectFormat', $js);
    }

    public function test_catalog_with_multi_filters_preserves_query_params_in_hidden_fields(): void
    {
        $publisher = $this->publisher();

        Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Query Param DE',
            'site_url' => 'https://query-param-de.test',
            'domain' => 'query-param-de.test',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Query param fixture.',
            'verified' => true,
            'active' => true,
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', [
                'country' => 'de,at',
                'language' => 'de',
                'category' => 'marketing',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="selectedCountry"[^>]*value="de,at"|value="de,at"[^>]*id="selectedCountry"/',
            $html
        );
        // Group key never becomes a filter value — only real country codes.
        $this->assertStringNotContainsString('value="dach_plus"', $html);
        $this->assertStringNotContainsString('country=dach_plus', $html);
        $this->assertStringContainsString('countryGroupLabels', $html);
        $this->assertStringContainsString('"dach_plus":"DACH+"', $html);
        $this->assertMatchesRegularExpression(
            '/id="selectedLanguage"[^>]*value="de"|value="de"[^>]*id="selectedLanguage"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="selectedCategory"[^>]*value="marketing"|value="marketing"[^>]*id="selectedCategory"/',
            $html
        );
    }
}
