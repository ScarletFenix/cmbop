<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CatalogLiveSearchRolloutTest extends TestCase
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
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_live_search_is_enabled_by_default(): void
    {
        $this->assertTrue(config('catalog.live_search.enabled'));
    }

    public function test_results_endpoint_works_when_live_search_enabled(): void
    {
        Config::set('catalog.live_search.enabled', true);

        $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog.results', ['search' => 'acme']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_results_endpoint_404s_when_live_search_disabled(): void
    {
        Config::set('catalog.live_search.enabled', false);

        $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog.results', ['search' => 'acme']))
            ->assertNotFound();
    }

    public function test_catalog_index_still_works_when_live_search_disabled(): void
    {
        Config::set('catalog.live_search.enabled', false);

        $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', ['search' => 'acme', 'sort' => 'price_asc']))
            ->assertOk()
            ->assertSee('id="catalogResults"', false)
            ->assertSee('liveSearch: false', false);
    }

    public function test_catalog_config_exposes_live_search_true_when_enabled(): void
    {
        Config::set('catalog.live_search.enabled', true);

        $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('liveSearch: true', false);
    }
}
