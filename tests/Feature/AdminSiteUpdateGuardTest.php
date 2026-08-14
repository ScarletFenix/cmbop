<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteDescriptionRules;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSiteUpdateGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Guard Site',
            'site_url' => 'https://guard-site.example',
            'domain' => 'guard-site.example',
            'da' => 40,
            'dr' => 42,
            'traffic' => 15000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Admin update guard listing description. ', 3),
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_update_cannot_flip_active_or_verified(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Guard Site',
                'site_url' => 'https://guard-site.example',
                'da' => 40,
                'dr' => 42,
                'traffic' => 15000,
                'active' => 0,
                'verified' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertTrue((bool) $site->active);
    }

    public function test_update_cannot_activate_or_verify_a_pending_site(): void
    {
        $site = $this->site([
            'site_name' => 'Pending Guard',
            'site_url' => 'https://pending-guard.example',
            'domain' => 'pending-guard.example',
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'da' => 41,
                'active' => 1,
                'verified' => 1,
            ])
            ->assertOk();

        $site->refresh();
        $this->assertFalse((bool) $site->verified);
        $this->assertFalse((bool) $site->active);
        $this->assertSame(41, (int) $site->da);
    }

    public function test_update_rejects_duplicate_domain(): void
    {
        $this->site();
        $other = $this->site([
            'site_name' => 'Other Guard',
            'site_url' => 'https://other-guard.example',
            'domain' => 'other-guard.example',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $other->id), [
                'site_url' => 'https://www.guard-site.example/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->assertSame('other-guard.example', $other->fresh()->domain);
    }

    public function test_update_rejects_invalid_metrics(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'da' => 101,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['da']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'dr' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dr']);

        $this->assertSame(40, (int) $site->fresh()->da);
        $this->assertSame(42, (int) $site->fresh()->dr);
    }

    public function test_update_rejects_short_description_when_sent(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'description' => 'Too short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_update_rejects_invalid_country_language_pair(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'country' => 'de',
                'language' => 'en',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['language']);

        $this->assertSame('de', $site->fresh()->language);
    }

    public function test_partial_metrics_update_still_succeeds(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Renamed Guard',
                'da' => 55,
                'dr' => 60,
                'traffic' => 20000,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('Renamed Guard', $site->site_name);
        $this->assertSame(55, (int) $site->da);
        $this->assertSame(60, (int) $site->dr);
        $this->assertSame(20000, (int) $site->traffic);
        $this->assertTrue((bool) $site->metrics_manual);
        $this->assertTrue((bool) $site->verified);
        $this->assertTrue((bool) $site->active);
    }

    public function test_update_keeps_existing_site_image_path_string(): void
    {
        $site = $this->site([
            'site_image' => 'sites/existing.jpg',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'site_image' => 'sites/existing.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('sites/existing.jpg', $site->fresh()->site_image);
    }

    public function test_update_accepts_free_text_link_type_from_dedicated_editor(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'link_type' => 'Guest Post',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Guest Post', $site->fresh()->link_type);
        $this->assertTrue(Site::ensureLinkTypeColumn());
    }

    public function test_update_syncs_categories_json_from_category_field(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'category' => 'Business & Finance',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('Business & Finance', $site->category);
        $this->assertSame(['Business & Finance'], $site->categories);
    }

    public function test_update_keeps_secondary_niches_when_primary_category_is_resent(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News', 'Business & Finance'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'category' => 'News',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('News', $site->category);
        $this->assertSame(['News', 'Business & Finance'], $site->categories);
    }

    public function test_update_replaces_primary_niche_without_dropping_others(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News', 'Technology & Gadgets'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'category' => 'Business & Finance',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('Business & Finance', $site->category);
        $this->assertSame(['Business & Finance', 'Technology & Gadgets'], $site->categories);
    }

    public function test_update_ignores_blank_category_and_keeps_niches(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News', 'Business & Finance'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'category' => '',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('News', $site->category);
        $this->assertSame(['News', 'Business & Finance'], $site->categories);
    }

    public function test_update_keeps_categories_when_category_is_omitted(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'da' => 55,
            ])
            ->assertOk();

        $site->refresh();
        $this->assertSame(55, (int) $site->da);
        $this->assertSame('News', $site->category);
        $this->assertSame(['News'], $site->categories);
    }

    public function test_update_clears_empty_country_and_language(): void
    {
        $site = $this->site([
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'country' => '',
                'language' => '',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue(blank($site->country));
        $this->assertTrue(blank($site->language));
        $this->assertEmpty($site->countries);
        $this->assertEmpty($site->languages);
    }

    public function test_update_clears_empty_description_and_example_url(): void
    {
        $site = $this->site([
            'description' => str_repeat('Admin update guard listing description. ', 3),
            'example_url' => 'https://guard-site.example/sample',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'description' => '',
                'example_url' => '',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue(blank($site->description));
        $this->assertTrue(blank($site->example_url));
    }

    public function test_update_rejects_description_over_word_max(): void
    {
        $site = $this->site();
        $tooLong = implode(' ', array_fill(0, SiteDescriptionRules::MAX_WORDS + 1, 'word'));

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'description' => $tooLong,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);

        $this->assertSame(
            str_repeat('Admin update guard listing description. ', 3),
            $site->fresh()->description
        );
    }

    public function test_admin_update_payload_is_declared_once(): void
    {
        $src = (string) file_get_contents(app_path('Http/Controllers/Admin/SiteController.php'));
        $this->assertSame(1, preg_match_all('/function adminUpdatePayload\s*\(/', $src));
        $this->assertStringNotContainsString('$metricMerge', $src);
        $this->assertDoesNotMatchRegularExpression('/^<<<<<<<|^=======|^>>>>>>>/m', $src);
    }
}
