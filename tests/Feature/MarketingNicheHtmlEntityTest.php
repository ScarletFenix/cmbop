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
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

/**
 * Niches like "Business & Finance" are Blade-escaped into data-value as
 * &amp;. When the hidden input (or a draft restore) submits the entity form,
 * marketing save used to reject the niche and skip the optional screenshot.
 */
class MarketingNicheHtmlEntityTest extends TestCase
{
    use CreatesBlogUploads;
    use RefreshDatabase;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);
        Storage::fake('public');

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Entity Niche Site',
            'site_url' => 'https://entity-niche.example',
            'domain' => 'entity-niche.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'News',
            'categories' => ['News'],
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Entity niche regression',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ]);
    }

    public function test_html_entity_encoded_ampersand_niche_saves_with_screenshot(): void
    {
        $site = $this->makeSite();
        $niche = Category::where('name', 'Business & Finance')->value('name');
        $this->assertSame('Business & Finance', $niche);

        $file = $this->fakeBlogUpload('marketer-shot.jpg', 640, 400);

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'da' => 41,
                'dr' => 52,
                'traffic' => 18000,
                'language' => 'en',
                'country' => 'us',
                // Browser / multi-select may submit the Blade-escaped form.
                'categories' => 'Business &amp; Finance',
                'site_image' => $file,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]))
            ->assertSessionHasNoErrors();

        $site->refresh();
        $this->assertSame(41, (int) $site->da);
        $this->assertContains('Business & Finance', $site->categories ?? []);
        $this->assertFalse(in_array('Business &amp; Finance', $site->categories ?? [], true));
        $this->assertNotEmpty($site->site_image);
        Storage::disk('public')->assertExists($site->site_image);
    }

    public function test_pipe_separated_entity_niches_normalize_to_real_names(): void
    {
        $site = $this->makeSite();
        $second = Category::where('name', 'News')->value('name')
            ?? Category::query()->where('name', '!=', 'Business & Finance')->value('name');
        $this->assertNotEmpty($second);

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'da' => 22,
                'dr' => 33,
                'traffic' => 9000,
                'language' => 'en',
                'country' => 'us',
                'categories' => 'Business &amp; Finance|'.$second,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]))
            ->assertSessionHasNoErrors();

        $site->refresh();
        $this->assertContains('Business & Finance', $site->categories ?? []);
        $this->assertContains($second, $site->categories ?? []);
    }
}
