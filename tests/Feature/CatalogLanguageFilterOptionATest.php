<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogLanguageFilter;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Option A: language-only → all sites offering that language (any country).
 * Language + country → AND. Selecting language never auto-sets country.
 * Same sequence for every language code.
 */
class CatalogLanguageFilterOptionATest extends TestCase
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
        $this->advertiser->roles()->sync([$advertiserRole->id]);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->sync([$publisherRole->id]);
    }

    private function site(
        string $slug,
        string $country,
        string $language,
        string $name,
        array $languages = [],
        bool $bulk = false,
    ): Site {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => $country,
            'countries' => [$country],
            'language' => $language,
            'languages' => $languages !== [] ? $languages : [$language],
            'category' => 'marketing',
            'price' => 80,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Language filter Option A fixture.',
            'verified' => true,
            'active' => true,
            'bulk_discount_enabled' => $bulk ? 1 : 0,
            'bulk_discount_percent' => $bulk ? 15 : null,
        ]);
    }

    public function test_german_language_only_returns_all_german_sites_any_country(): void
    {
        $this->site('de-de', 'de', 'de', 'German In Germany');
        $this->site('us-de', 'us', 'de', 'German In United States');
        $this->site('us-en', 'us', 'en', 'English In United States');

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['language' => 'de']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('German In Germany', $html);
        $this->assertStringContainsString('German In United States', $html);
        $this->assertStringNotContainsString('English In United States', $html);
        // Language-only must not force a country param into the page boot.
        $this->assertMatchesRegularExpression('/countryParam:\s*""/', $html);
    }

    public function test_english_and_french_language_only_use_same_rule(): void
    {
        $this->site('fr-fr', 'fr', 'fr', 'French In France');
        $this->site('ca-fr', 'ca', 'fr', 'French In Canada');
        $this->site('uk-en', 'uk', 'en', 'English In UK');
        $this->site('de-en', 'de', 'en', 'English In Germany');

        $frHtml = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['language' => 'fr']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('French In France', $frHtml);
        $this->assertStringContainsString('French In Canada', $frHtml);
        $this->assertStringNotContainsString('English In UK', $frHtml);

        $enHtml = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['language' => 'en']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('English In UK', $enHtml);
        $this->assertStringContainsString('English In Germany', $enHtml);
        $this->assertStringNotContainsString('French In France', $enHtml);
    }

    public function test_language_and_country_and_together(): void
    {
        $this->site('de-de', 'de', 'de', 'German In Germany');
        $this->site('us-de', 'us', 'de', 'German In United States');
        $this->site('de-en', 'de', 'en', 'English In Germany');

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'language' => 'de',
                'country' => 'de',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('German In Germany', $html);
        $this->assertStringNotContainsString('German In United States', $html);
        $this->assertStringNotContainsString('English In Germany', $html);
    }

    public function test_multi_language_site_appears_for_offered_language(): void
    {
        $this->site('multi', 'at', 'de', 'AT Offers DE And EN', ['de', 'en']);
        $this->site('fr-only', 'fr', 'fr', 'FR Only');

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['language' => 'en']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('AT Offers DE And EN', $html);
        $this->assertStringNotContainsString('FR Only', $html);
    }

    public function test_live_results_endpoint_follows_language_only(): void
    {
        $this->site('live-de', 'ch', 'de', 'Live German CH');
        $this->site('live-en', 'ch', 'en', 'Live English CH');

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.results', ['language' => 'de']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Live German CH', $html);
        $this->assertStringNotContainsString('Live English CH', $html);
    }

    public function test_bulk_deals_respect_language_filter(): void
    {
        $this->site('bulk-de', 'de', 'de', 'Bulk German', bulk: true);
        $this->site('bulk-en', 'us', 'en', 'Bulk English', bulk: true);

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['language' => 'de']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bulk German', $html);
        $this->assertStringNotContainsString('Bulk English', $html);
    }

    public function test_js_does_not_auto_set_country_when_selecting_language(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('Selecting a language never auto-sets country', $js);
        // updateMultiFilter must not write country when type is language.
        $this->assertMatchesRegularExpression(
            "/function updateMultiFilter\(checkbox\)\s*\{[\s\S]*?scheduleCatalogFilterLive/",
            $js
        );
        preg_match(
            "/function updateMultiFilter\(checkbox\)\s*\{([\s\S]*?)(?=\nfunction |\n\/\* Phase 0)/",
            $js,
            $m
        );
        $body = $m[1] ?? '';
        $this->assertNotSame('', $body);
        $this->assertStringNotContainsString('selectedMultiFilters.country.push', $body);
        $this->assertStringNotContainsString('default_language_by_country', $body);
    }

    public function test_helper_normalize_and_constrain(): void
    {
        $this->site('norm-de', 'de', 'DE', 'Uppercase Lang Column');
        // Force uppercase language to prove LOWER(TRIM) match.
        Site::query()->where('site_name', 'Uppercase Lang Column')->update(['language' => 'DE']);

        $filter = app(CatalogLanguageFilter::class);
        $this->assertSame(['de', 'en'], $filter->normalizeCodes([' DE ', 'en', 'de', '']));

        $ids = Site::query()
            ->tap(fn ($q) => $filter->constrainQuery($q, ['de']))
            ->pluck('site_name')
            ->all();

        $this->assertContains('Uppercase Lang Column', $ids);
    }
}
