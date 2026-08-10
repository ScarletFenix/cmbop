<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the merge regression where buildCatalogListing() returned the full
 * catalog view via compact('orderingSubmission') without defining that
 * variable — 500 on GET /advertiser/catalog.
 */
class CatalogOrderingSubmissionCompactTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advRole->id,
        ]);
        $this->advertiser->roles()->attach($advRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);

        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Ordering Compact Blog',
            'site_url' => 'https://ordering-compact.example',
            'domain' => 'ordering-compact.example',
            'example_url' => 'https://ordering-compact.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 9000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'categories' => ['Marketing'],
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Catalog orderingSubmission compact regression fixture.',
            'verified' => true,
            'active' => 1,
        ]);
    }

    public function test_build_catalog_listing_returns_array_not_full_page_view(): void
    {
        $src = (string) file_get_contents(app_path('Http/Controllers/Advertiser/CatalogController.php'));

        $this->assertMatchesRegularExpression(
            '/function buildCatalogListing\(Request \$request\): array\s*\{[\s\S]*?return \[\s*\'sites\'\s*=>\s*\$sites,/s',
            $src
        );

        // Exactly one full-page catalog view — owned by index(), which defines
        // $orderingSubmission before compact().
        $this->assertSame(
            1,
            substr_count($src, "return view('advertiser.catalog', compact(")
        );
        $this->assertStringContainsString(
            '$orderingSubmission = $this->resolveActiveLibraryOrdering($request);',
            $src
        );
    }

    public function test_advertiser_catalog_and_dashboard_load(): void
    {
        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Undefined variable $orderingSubmission');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk();
    }
}
