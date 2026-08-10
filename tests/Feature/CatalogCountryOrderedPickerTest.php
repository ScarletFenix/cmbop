<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogCountryInventory;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogCountryOrderedPickerTest extends TestCase
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

    private function site(User $publisher, string $country, string $domain): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Picker '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => $country,
            'countries' => [$country],
            'language' => $country === 'us' ? 'en' : 'de',
            'languages' => [$country === 'us' ? 'en' : 'de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Country picker fixture for ordered sections.',
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
        Cache::flush();
    }

    public function test_catalog_country_dropdown_renders_ordered_sections_and_helpers(): void
    {
        $publisher = $this->publisher();
        // Fill Popular with non-EU markets so ie stays under Small Europe.
        foreach (['us', 'ca', 'au', 'mx', 'br', 'ar', 'cn', 'tw', 'ae', 'sa'] as $i => $code) {
            for ($n = 0; $n < (20 - $i); $n++) {
                $this->site($publisher, $code, $code.'-pop-'.$n.'.test');
            }
        }
        $this->site($publisher, 'de', 'de-a.test');
        $this->site($publisher, 'fr', 'fr-a.test');
        $this->site($publisher, 'se', 'se-a.test');
        $this->site($publisher, 'ie', 'ie-a.test');
        $this->site($publisher, 'uk', 'uk-a.test');
        $this->site($publisher, 'at', 'at-a.test');
        $this->site($publisher, 'li', 'li-a.test');

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-section="popular"', $html);
        $this->assertStringContainsString('data-section="recent"', $html);
        $this->assertStringContainsString('data-section="big_europe"', $html);
        $this->assertStringContainsString('data-section="nordics"', $html);
        $this->assertStringContainsString('data-section="small_europe"', $html);
        $this->assertStringContainsString('Select DACH+', $html);
        $this->assertStringContainsString('Select Nordics', $html);
        $this->assertStringContainsString('data-country-group="dach_plus"', $html);
        $this->assertStringContainsString('data-country-group="nordics"', $html);

        // No extra convenience combos.
        $this->assertStringNotContainsString('Select Iberia', $html);
        $this->assertStringNotContainsString('Select Benelux', $html);
        $this->assertStringNotContainsString('data-section="iberia"', $html);

        // uk appears once as a checkbox value.
        $this->assertSame(1, substr_count($html, 'value="uk"'));
        $this->assertMatchesRegularExpression(
            '/data-section="small_europe"[\s\S]*?value="ie"/',
            $html
        );

        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('CatalogCountryPicker', $js);
        $this->assertStringContainsString('catalog.recentCountries', $js);
        $this->assertStringContainsString('data-country-group', $js);
    }

    public function test_picker_sections_dedupe_popular_from_buckets_and_keep_big_europe_order(): void
    {
        $publisher = $this->publisher();
        // Occupy Popular with other markets so Big Europe keeps all seven codes.
        foreach (['us', 'ca', 'au', 'mx', 'br', 'ar', 'cn', 'tw', 'ae', 'sa'] as $i => $code) {
            for ($n = 0; $n < (30 - $i); $n++) {
                $this->site($publisher, $code, $code.'-hot-'.$n.'.test');
            }
        }
        foreach (['de', 'fr', 'it', 'es', 'uk', 'nl', 'pl'] as $code) {
            $this->site($publisher, $code, $code.'-eu.test');
        }
        $this->site($publisher, 'se', 'se-only.test');
        $this->site($publisher, 'ie', 'ie-only.test');

        $sections = app(CatalogCountryInventory::class)->pickerSections();
        $byKey = collect($sections['sections'])->keyBy('key');

        $this->assertTrue($byKey->has('popular'));
        $this->assertTrue($byKey->has('recent'));
        $this->assertTrue($byKey->has('big_europe'));

        $popularCodes = collect($byKey['popular']['options'])->pluck('code')->all();
        $bigEuropeCodes = collect($byKey['big_europe']['options'])->pluck('code')->all();

        foreach ($popularCodes as $code) {
            $this->assertNotContains($code, $bigEuropeCodes);
        }

        $this->assertSame(['de', 'fr', 'it', 'es', 'uk', 'nl', 'pl'], $bigEuropeCodes);

        $this->assertSame(1, collect($sections['sections'])
            ->flatMap(fn ($s) => $s['options'])
            ->where('code', 'uk')
            ->count());

        $small = collect($byKey['small_europe']['options'] ?? [])->pluck('code')->all();
        $this->assertContains('ie', $small);

        $groupKeys = collect($sections['groups'])->pluck('key')->all();
        $this->assertSame(['dach_plus', 'nordics'], $groupKeys);
    }
}
