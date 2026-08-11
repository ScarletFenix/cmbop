<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogUrlQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogQualityGateFilterTest extends TestCase
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

    private function site(User $publisher, array $extra = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Quality Gate Site',
            'site_url' => 'https://quality-gate-'.uniqid().'.test',
            'domain' => 'quality-gate-'.uniqid().'.test',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Quality gate fixture',
            'verified' => true,
            'active' => true,
        ], $extra));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_quality_param_is_url_allowlisted_and_filters_catalog(): void
    {
        $this->assertContains('quality', CatalogUrlQuery::KEYS);

        $publisher = $this->publisher();
        $this->site($publisher, [
            'site_name' => 'Strong Site',
            'da' => 40,
            'dr' => 50,
            'traffic' => 15000,
        ]);
        $this->site($publisher, [
            'site_name' => 'Weak Site',
            'da' => 10,
            'dr' => 10,
            'traffic' => 500,
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', ['quality' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Strong Site', $html);
        $this->assertStringNotContainsString('Weak Site', $html);
        $this->assertStringContainsString('id="catalogQualityGate"', $html);
        $this->assertStringContainsString('Quality bar (DA/DR/traffic)', $html);
    }

    public function test_catalog_without_quality_still_shows_below_bar_sites(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, [
            'site_name' => 'Weak Still Visible',
            'da' => 5,
            'dr' => 5,
            'traffic' => 100,
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Weak Still Visible', $html);
    }
}
