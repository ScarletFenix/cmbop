<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSitesSchemaDriftResilienceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);

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

        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Admin Drift Site',
            'site_url' => 'https://admin-drift.example',
            'domain' => 'admin-drift.example',
            'da' => 30,
            'dr' => 35,
            'traffic' => 5000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'Technology',
            'categories' => ['Technology'],
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Admin sites schema drift resilience fixture.',
            'verified' => false,
            'active' => false,
            'publisher_accepted_at' => now(),
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
    }

    public function test_admin_sites_index_loads_without_onboarding_status_column(): void
    {
        $this->dropSitesColumnIfPresent('onboarding_status');

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong');

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['needs_review' => 1]))
            ->assertOk();
    }

    public function test_admin_sites_index_loads_without_countries_json_column(): void
    {
        $this->dropSitesColumnIfPresent('countries');

        $this->actingAs($this->admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong');

        $this->actingAs($this->admin)
            ->get(route('admin.sites.records', ['missing_market' => 1]))
            ->assertOk();
    }

    public function test_admin_user_sites_ajax_skips_missing_optional_columns(): void
    {
        foreach (['countries', 'languages', 'categories', 'onboarding_status', 'screenshot_path', 'enrichment_status'] as $column) {
            $this->dropSitesColumnIfPresent($column);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.users.sites', $this->publisher->id))
            ->assertOk()
            ->assertJsonPath('publisher.id', $this->publisher->id)
            ->assertJsonCount(1, 'sites');
    }

    private function dropSitesColumnIfPresent(string $column): void
    {
        if (! Schema::hasColumn('sites', $column)) {
            return;
        }

        try {
            Schema::table('sites', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        } catch (\Throwable) {
            // SQLite may refuse some drops; leave column in place for that case.
        }
    }
}
