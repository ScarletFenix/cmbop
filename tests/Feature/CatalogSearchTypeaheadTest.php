<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogSearchTypeaheadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role, array $attrs = []): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ], $attrs));
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function site(string $domain, string $name, array $extra = []): Site
    {
        $publisher = $this->userWithRole('publisher');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain.'/blog',
            'domain' => $domain,
            'da' => 40,
            'dr' => 60,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Typeahead fixture.',
            'verified' => true,
            'active' => true,
        ], $extra));
    }

    public function test_suggest_returns_matching_sites_for_normals(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $hit = $this->site('typeahead-hit.example', 'Typeahead Hit Brand', ['dr' => 80]);
        $this->site('other-noise.example', 'Other Noise Brand', ['dr' => 10]);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.catalog.suggest', ['q' => 'typeahead-hit']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('in_hide_mode', false)
            ->assertJsonPath('suggestions.0.id', $hit->id)
            ->assertJsonPath('suggestions.0.name', 'Typeahead Hit Brand')
            ->assertJsonPath('suggestions.0.host', 'typeahead-hit.example')
            ->assertJsonPath('suggestions.0.masked', false)
            ->assertJsonCount(1, 'suggestions');
    }

    public function test_suggest_masks_identity_in_hide_mode(): void
    {
        $advertiser = $this->userWithRole('advertiser', [
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);
        $site = $this->site('masked-suggest.example', 'Masked Suggest Brand');

        $json = $this->actingAs($advertiser)
            ->getJson(route('advertiser.catalog.suggest', ['q' => 'masked-suggest']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('in_hide_mode', true)
            ->assertJsonPath('suggestions.0.id', $site->id)
            ->assertJsonPath('suggestions.0.masked', true)
            ->json();

        $row = $json['suggestions'][0];
        $this->assertStringNotContainsString('Masked Suggest Brand', $row['name']);
        $this->assertStringNotContainsString('masked-suggest.example', $row['host']);
        $this->assertStringContainsString('***', $row['host']);
    }

    public function test_suggest_ignores_short_queries(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site('ab.example', 'AB Brand');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.catalog.suggest', ['q' => 'a']))
            ->assertOk()
            ->assertJsonPath('suggestions', []);
    }

    public function test_catalog_search_typing_uses_live_rows_not_suggest_dropdown(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertStringContainsString('initCatalogSearchLiveRows', $js);
        $this->assertStringContainsString('CATALOG_SEARCH_MIN_CHARS', $js);
        $this->assertStringContainsString('CATALOG_FILTER_LIVE_MS', $js);
        $this->assertStringContainsString('scheduleCatalogFilterLive', $js);
        $this->assertStringNotContainsString('SUGGEST_DEBOUNCE_MS', $js);
        $this->assertStringNotContainsString('fetchSuggestions', $js);
        $this->assertStringNotContainsString('initCatalogSearchTypeahead', $js);
        $this->assertStringNotContainsString('hideSuggestUi', $js);
        // Dropdown chrome removed; API route kept for a future quick-jump.
        $this->assertStringContainsString("route('advertiser.catalog.suggest')", $blade);
        $this->assertStringContainsString('suggest: @json(route(\'advertiser.catalog.suggest\'))', $blade);
        $this->assertStringNotContainsString('id="catalogSuggestList"', $blade);
        $this->assertStringNotContainsString('data-catalog-typeahead', $blade);
        $this->assertStringNotContainsString('.catalog-suggest-list', $css);
        $this->assertStringContainsString('Results update as you type', $blade);
        $this->assertStringContainsString('catalog-search-field', $blade);
    }

    public function test_live_results_search_returns_full_catalog_rows(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site('live-row-hit.example', 'Live Row Hit Brand', ['dr' => 80]);
        $this->site('live-row-miss.example', 'Other Noise Brand', ['dr' => 10]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results', ['search' => 'Live Row Hit']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Live Row Hit Brand', $html);
        $this->assertStringNotContainsString('Other Noise Brand', $html);
        // Real row chrome (not a suggest dropdown item).
        $this->assertStringContainsString('catalog-table', $html);
        $this->assertStringContainsString('catalog-metric', $html);
    }

    public function test_guests_cannot_use_suggest(): void
    {
        $this->getJson(route('advertiser.catalog.suggest', ['q' => 'hello']))
            ->assertStatus(401);
    }

    public function test_catalog_page_includes_suggest_list_markup(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('id="catalogSuggestList"', false);
    }
}
