<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminEnrichmentsSchemaDriftResilienceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $publisher->roles()->attach($pubRole->id);

        Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Enrich Drift Site',
            'site_url' => 'https://enrich-drift.example',
            'domain' => 'enrich-drift.example',
            'da' => 20,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Enrichment schema drift fixture site.',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_enrichment_index_ok_when_runs_table_missing(): void
    {
        Schema::dropIfExists('site_enrichment_runs');
        $this->assertFalse(Schema::hasTable('site_enrichment_runs'));

        $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->assertSee('Publisher Enrichment');
    }

    public function test_enrichment_index_ok_when_metrics_columns_missing(): void
    {
        foreach (['metrics_fetched_at', 'screenshot_path', 'screenshot_fetched_at', 'enrichment_status'] as $column) {
            $this->dropSitesColumnIfPresent($column);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong');
    }

    public function test_rerun_failed_soft_fails_when_runs_table_missing(): void
    {
        Schema::dropIfExists('site_enrichment_runs');

        $this->actingAs($this->admin)
            ->postJson(route('admin.site-enrichment.rerun-failed'), ['limit' => 5])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('count', 0);
    }

    public function test_enrichment_index_ok_with_full_schema(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk();
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
            // SQLite may refuse some drops.
        }
    }
}
