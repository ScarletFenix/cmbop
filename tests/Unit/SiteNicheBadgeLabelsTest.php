<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteNicheBadgeLabelsTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);
        Category::flushNicheLookupCache();

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(array $extra): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Niche Badge Unit',
            'site_url' => 'https://niche-badge-unit.example',
            'domain' => 'niche-badge-unit.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Unit badge labels.',
            'verified' => true,
            'active' => 1,
        ], $extra));
    }

    public function test_categories_array_accessor_does_not_split_comma_niche(): void
    {
        $site = $this->makeSite([
            'category' => 'Marketing, PR & Advertising',
            'categories' => ['Marketing, PR & Advertising'],
        ]);

        $this->assertSame(
            ['Marketing, PR & Advertising'],
            $site->categories_array
        );
        $this->assertSame(
            ['Marketing, PR & Advertising'],
            $site->nicheBadgeLabels()
        );
    }

    public function test_legacy_only_category_string_is_one_label(): void
    {
        $site = $this->makeSite([
            'category' => 'Events, Conferences & Trade Fairs',
            'categories' => null,
        ]);

        $this->assertSame(
            ['Events, Conferences & Trade Fairs'],
            $site->nicheBadgeLabels()
        );
        $this->assertSame(
            ['Events, Conferences & Trade Fairs'],
            $site->categories_array
        );
    }

    public function test_site_source_no_longer_explodes_categories_on_commas(): void
    {
        $src = (string) file_get_contents(app_path('Models/Site.php'));

        $this->assertStringContainsString('function nicheBadgeLabels', $src);
        $this->assertStringContainsString('Category::parseCatalogCategoryParam', $src);
        $this->assertStringNotContainsString(
            "return array_map('trim', explode(',', \$this->categories));",
            $src
        );
    }

    public function test_display_niche_labels_prefers_json_entries_unsplit(): void
    {
        $this->assertSame(
            ['Marketing, PR & Advertising', 'Health & Wellness'],
            Category::displayNicheLabels(
                ['Marketing, PR & Advertising', 'Health & Wellness'],
                'should-be-ignored-when-json-present'
            )
        );
    }
}
