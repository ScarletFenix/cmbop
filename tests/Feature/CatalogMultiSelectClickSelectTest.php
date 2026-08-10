<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
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

        $this->assertStringContainsString('data-singular="country"', $html);
        $this->assertStringContainsString('data-plural="countries"', $html);
        $this->assertStringContainsString('data-singular="category"', $html);
        $this->assertStringContainsString('data-plural="categories"', $html);
        $this->assertStringContainsString('data-singular="language"', $html);
        $this->assertStringContainsString('data-plural="languages"', $html);

        $this->assertStringContainsString('role="option"', $html);
        $this->assertStringContainsString('aria-multiselectable="true"', $html);
        // Checkboxes remain for state; CSS hides them for catalog wrappers only.
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function test_click_select_css_hides_catalog_checkboxes_and_styles_selected_rows(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/multi-select.css'));

        $this->assertStringContainsString(
            '.multi-select-wrapper[data-multi-select] .option-item input[type="checkbox"]',
            $css
        );
        $this->assertStringContainsString('clip: rect(0, 0, 0, 0)', $css);
        $this->assertStringContainsString('.option-item.is-selected', $css);
        $this->assertStringContainsString('.selected-items.is-compact', $css);
        $this->assertStringContainsString('selected-tag--count', $css);
    }

    public function test_click_select_js_syncs_highlight_and_compact_overflow_count(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function syncOptionSelectedState(type)', $js);
        $this->assertStringContainsString('function multiDisplayOverflows(container)', $js);
        $this->assertStringContainsString('function renderCompactMultiDisplay(', $js);
        $this->assertStringContainsString('function clearMultiFilter(type)', $js);
        $this->assertStringContainsString('selected-tag--count', $js);
        $this->assertStringContainsString('filterClearAll', $js);
        $this->assertStringContainsString("aria-selected', on ? 'true' : 'false'", $js);
        $this->assertStringContainsString('No visible checkboxes', $js);

        // Reopen path re-syncs highlights.
        $this->assertStringContainsString('syncOptionSelectedState(type)', $js);

        // Country Recent still pins on select.
        $this->assertStringContainsString('CatalogCountryPicker.rememberFromSelection([value])', $js);
        $this->assertStringContainsString('CatalogCountryPicker.renderRecent()', $js);
    }
}
