<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketplace\CountryLanguagePairs;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CountryFirstLanguagePairsTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $advertiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);
    }

    public function test_publisher_websites_page_exposes_country_language_map(): void
    {
        $response = $this->actingAs($this->publisher)->get(route('publisher.websites'));
        $response->assertOk();

        $map = $response->viewData('countryLanguageMap');
        $this->assertIsArray($map);
        $this->assertArrayHasKey('de', $map);

        $html = $response->getContent();
        $this->assertStringContainsString('Select country first', $html);
        $this->assertStringContainsString('PublisherWebsitesConfig', $html);
        $this->assertStringContainsString('countryLanguageMap', $html);
    }

    public function test_publisher_cannot_store_germany_with_english(): void
    {
        Queue::fake();
        $category = Category::query()->firstOrFail()->name;
        Country::marketplace()->where('code', 'de')->firstOrFail();
        Language::marketplace()->where('code', 'en')->firstOrFail();

        $response = $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'Bad Pair DE EN',
            'siteUrl' => 'https://bad-pair-de-en.example',
            'exampleUrl' => 'https://bad-pair-de-en.example/post',
            'da' => 40,
            'dr' => 45,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'en',
            'categories' => $category,
            'price' => 80,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Editorial guest post site for pair validation. ', 3),
        ]);

        $response->assertSessionHasErrors('language');
        $this->assertNull(Site::where('domain', 'bad-pair-de-en.example')->first());
    }

    public function test_publisher_can_store_uae_with_english(): void
    {
        Queue::fake();
        $category = Category::query()->firstOrFail()->name;

        $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'UAE English',
            'siteUrl' => 'https://uae-en-pair.example',
            'exampleUrl' => 'https://uae-en-pair.example/post',
            'da' => 40,
            'dr' => 45,
            'traffic' => 1000,
            'country' => 'ae',
            'language' => 'en',
            'categories' => $category,
            'price' => 80,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Editorial guest post site for pair validation. ', 3),
        ])->assertSessionHas('success');

        $site = Site::where('domain', 'uae-en-pair.example')->first();
        $this->assertNotNull($site);
        $this->assertSame('ae', $site->country);
        $this->assertSame('en', $site->language);
    }

    public function test_wizard_rejects_invalid_country_language_pair(): void
    {
        $this->actingAs($this->advertiser)->post(route('advertiser.wizard.market.save'), [
            'country' => 'de',
            'language' => 'en',
        ])->assertSessionHasErrors('language');
    }

    public function test_wizard_accepts_valid_country_language_pair(): void
    {
        $this->actingAs($this->advertiser)->post(route('advertiser.wizard.market.save'), [
            'country' => 'de',
            'language' => 'de',
        ])->assertRedirect(route('advertiser.wizard.publishers'));

        $this->assertSame('de', session('guest_post_wizard.country'));
        $this->assertSame('de', session('guest_post_wizard.language'));
    }

    public function test_catalog_intersects_language_filter_with_country_pairs(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'DE German',
            'site_url' => 'https://de-german.example',
            'domain' => 'de-german.example',
            'da' => 50,
            'dr' => 50,
            'traffic' => 5000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 100,
            'publication_time' => 'permanent',
            'description' => 'German market site',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'AE English',
            'site_url' => 'https://ae-english.example',
            'domain' => 'ae-english.example',
            'da' => 50,
            'dr' => 50,
            'traffic' => 5000,
            'country' => 'ae',
            'language' => 'en',
            'category' => 'News',
            'price' => 100,
            'publication_time' => 'permanent',
            'description' => 'Gulf English site',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        // Germany + English: English is not paired with DE, so the language
        // token is dropped and only the country filter remains → DE sites only.
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['country' => 'de', 'language' => 'en']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ae-english.example', $html);
        $this->assertStringContainsString('de-german.example', $html);

        $pairs = app(CountryLanguagePairs::class);
        $this->assertFalse($pairs->isAllowedPair('de', 'en'));
    }
}
