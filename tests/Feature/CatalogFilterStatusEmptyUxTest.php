<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogFilterStatus;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\CountryLanguageSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogFilterStatusEmptyUxTest extends TestCase
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

    private function site(User $publisher, string $country, string $domain, string $name = 'Status Site'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => $country,
            'countries' => [$country],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Filter status fixture for empty UX and AND copy.',
            'verified' => true,
            'active' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CountryLanguageSeeder::class);
        Cache::flush();
    }

    public function test_search_plus_country_shows_matching_copy_and_aria_live(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, 'de', 'kids-de.test', 'Kids Garden Magazine');

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', [
                'search' => 'kids',
                'country' => 'de',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Matching “kids” in Germany', $html);
        $this->assertStringContainsString('id="catalogLiveStatus"', $html);
        $this->assertMatchesRegularExpression(
            '/id="catalogLiveStatus"[^>]*>\s*Matching “kids” in Germany/u',
            $html
        );
    }

    public function test_empty_country_state_clear_country_keeps_search(): void
    {
        // No DE kids sites — only AT inventory for neighbor suggestions.
        $publisher = $this->publisher();
        $this->site($publisher, 'at', 'at-news.test', 'Austria News');

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', [
                'search' => 'kids',
                'country' => 'de',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No sites matching “kids” in Germany', $html);
        $this->assertStringContainsString('catalog-clear-country', $html);
        $this->assertStringContainsString('Clear country', $html);
        $this->assertStringContainsString('Try Language: German', $html);

        $this->assertTrue(
            (bool) preg_match('/<a\b[^>]*catalog-clear-country[^>]*>/i', $html, $m),
            'Expected a Clear country anchor'
        );
        $clearCountryAnchor = $m[0];
        $this->assertStringContainsString('search=kids', html_entity_decode($clearCountryAnchor));
        $this->assertStringNotContainsString('country=', html_entity_decode($clearCountryAnchor));

        // Neighbor from DACH+ with inventory.
        $this->assertStringContainsString('Also try:', $html);
        $this->assertStringContainsString('Austria', $html);
    }

    public function test_filter_status_service_clear_country_query_preserves_search(): void
    {
        $status = app(CatalogFilterStatus::class);
        $request = Request::create('/advertiser/catalog', 'GET', [
            'search' => 'kids',
            'country' => 'de',
            'sort' => 'price_asc',
            'page' => 2,
        ]);

        $recovery = $status->emptyRecovery($request);
        $this->assertNotNull($recovery['clear_country_url']);
        $this->assertStringContainsString('search=kids', $recovery['clear_country_url']);
        $this->assertStringContainsString('sort=price_asc', $recovery['clear_country_url']);
        $this->assertStringNotContainsString('country=', $recovery['clear_country_url']);
        $this->assertStringNotContainsString('page=', $recovery['clear_country_url']);

        $summary = $status->summarize($request, 3, 1, 3);
        $this->assertStringContainsString('Matching “kids” in Germany', $summary['text']);
        $this->assertSame($summary['text'], $summary['announce']);
    }

    public function test_empty_tag_state_shows_clear_tag_and_named_copy(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, 'de', 'untagged-de.test', 'Untagged Garden');

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', [
                'search' => 'garden',
                'tag' => 'sponsored',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No sites matching “garden” · Sponsored', $html);
        $this->assertStringContainsString('catalog-clear-tag', $html);
        $this->assertStringContainsString('Clear tag', $html);
        $this->assertStringContainsString('Clear the tag filter (Sponsored) to see more listings', $html);

        $this->assertTrue(
            (bool) preg_match('/<a\b[^>]*catalog-clear-tag[^>]*>/i', $html, $m),
            'Expected a Clear tag anchor'
        );
        $clearTagAnchor = $m[0];
        $this->assertStringContainsString('search=garden', html_entity_decode($clearTagAnchor));
        $this->assertStringNotContainsString('tag=', html_entity_decode($clearTagAnchor));
        $this->assertStringNotContainsString('sponsored=', html_entity_decode($clearTagAnchor));
    }

    public function test_filter_status_service_clear_tag_query_preserves_search(): void
    {
        $status = app(CatalogFilterStatus::class);
        $request = Request::create('/advertiser/catalog', 'GET', [
            'search' => 'kids',
            'tag' => 'partner_material',
            'sponsored' => '1',
            'sort' => 'price_asc',
            'page' => 2,
        ]);

        $recovery = $status->emptyRecovery($request);
        $this->assertNotNull($recovery['clear_tag_url']);
        $this->assertStringContainsString('search=kids', $recovery['clear_tag_url']);
        $this->assertStringContainsString('sort=price_asc', $recovery['clear_tag_url']);
        $this->assertStringNotContainsString('tag=', $recovery['clear_tag_url']);
        $this->assertStringNotContainsString('sponsored=', $recovery['clear_tag_url']);
        $this->assertStringNotContainsString('page=', $recovery['clear_tag_url']);
        $this->assertStringContainsString('Partner article', $recovery['body']);
        $this->assertStringContainsString('tag filter', $recovery['body']);

        $summary = $status->summarize($request, 0);
        $this->assertStringContainsString('No sites matching “kids” · Partner article', $summary['text']);

        $tagOnly = $status->summarize(
            Request::create('/advertiser/catalog', 'GET', ['tag' => 'none']),
            0
        );
        $this->assertSame('No sites with No tags', $tagOnly['text']);

        $tagOnlyRecovery = $status->emptyRecovery(
            Request::create('/advertiser/catalog', 'GET', ['tag' => 'as_you_prefer'])
        );
        $this->assertNotNull($tagOnlyRecovery['clear_tag_url']);
        $this->assertStringContainsString('Clear the tag filter', $tagOnlyRecovery['body']);
        $this->assertStringContainsString('As you prefer', $tagOnlyRecovery['body']);
    }

    public function test_germany_has_primary_german_for_try_language(): void
    {
        $country = Country::where('code', 'de')->firstOrFail();
        $german = Language::where('code', 'de')->firstOrFail();
        $this->assertTrue(
            $country->primaryLanguages()->where('languages.id', $german->id)->exists()
            || $country->languages()->where('languages.id', $german->id)->exists()
        );
    }
}
