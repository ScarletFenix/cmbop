<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\CountryLanguageSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LiechtensteinMarketplaceCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CountryLanguageSeeder::class);
        $this->seed(CategoriesTableSeeder::class);
    }

    public function test_liechtenstein_is_in_marketplace_allowlist(): void
    {
        $codes = config('markets.allowed_country_codes', []);

        $this->assertContains('li', $codes);
        $this->assertContains('li', config('markets.europe_country_codes', []));
    }

    public function test_marketplace_scope_includes_liechtenstein_with_german(): void
    {
        $country = Country::marketplace()->where('code', 'li')->first();

        $this->assertNotNull($country);
        $this->assertSame('Liechtenstein', $country->name);
        $this->assertSame('Europe', $country->region);

        $german = Language::marketplace()->where('code', 'de')->first();
        $this->assertNotNull($german);
        $this->assertTrue(
            $country->languages()->where('languages.id', $german->id)->exists()
        );
        $this->assertTrue(
            (bool) $country->languages()->where('languages.id', $german->id)->first()?->pivot?->is_primary
        );
    }

    public function test_publisher_can_save_site_with_country_li(): void
    {
        Queue::fake();

        $role = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $publisher->roles()->attach($role->id);

        $category = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($category);

        $response = $this->actingAs($publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'Liechtenstein Gazette',
            'siteUrl' => 'li-gazette.example',
            'exampleUrl' => 'https://li-gazette.example/sample-post',
            'da' => 30,
            'dr' => 35,
            'traffic' => 4000,
            'country' => 'li',
            'language' => 'de',
            'categories' => $category,
            'price' => 90,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Editorial site based in Liechtenstein for guest posts. ', 3),
            'site_tag' => 'as_you_prefer',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();

        $site = Site::where('domain', 'li-gazette.example')->first();
        $this->assertNotNull($site);
        $this->assertSame('li', $site->country);
        $this->assertSame(['li'], $site->countries);
        $this->assertSame('de', $site->language);
    }
}
