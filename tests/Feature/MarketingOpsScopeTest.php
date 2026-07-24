<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingOpsScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Ops Scope Site',
            'site_url' => 'https://ops-scope.example',
            'domain' => 'ops-scope.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Scope test site',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_marketer_cannot_verify_or_activate_sites(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->marketer)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertForbidden();

        $this->actingAs($this->marketer)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertForbidden();

        $site->refresh();
        $this->assertFalse((bool) $site->verified);
        $this->assertFalse((bool) $site->active);
    }

    public function test_admin_can_still_verify_and_activate_sites(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertTrue((bool) $site->active);
    }

    public function test_marketer_is_blocked_from_non_ops_admin_tools(): void
    {
        $this->actingAs($this->marketer)
            ->get(route('admin.site-ratings.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->marketer)
            ->get(route('admin.community.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->marketer)
            ->get(route('admin.activity-logs.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->marketer)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertForbidden();

        $this->actingAs($this->marketer)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertForbidden();

        $this->actingAs($this->marketer)
            ->get(route('admin.users.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->marketer)
            ->get(route('admin.payments'))
            ->assertRedirect(route('marketing.dashboard'));
    }

    public function test_marketer_uses_marketing_url_prefix_not_admin(): void
    {
        $this->assertStringContainsString('/marketing/', route('marketing.dashboard', [], false));
        $this->assertStringNotContainsString('/admin/', route('marketing.sites.index', [], false));

        $this->actingAs($this->marketer)
            ->get('/admin/dashboard')
            ->assertRedirect('/marketing/dashboard');

        $this->actingAs($this->marketer)
            ->get('/admin/sites')
            ->assertRedirect('/marketing/sites');
    }

    public function test_marketer_can_open_ops_pages_and_sites_ui_hides_verify_active(): void
    {
        $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('add/edit sites', false)
            ->assertDontSee('Activity History', false);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('CAN_VERIFY_SITES = false', $html);
        $this->assertStringContainsString('CAN_TOGGLE_ACTIVE = false', $html);
        $this->assertStringContainsString('CAN_DELETE_PENDING_SITES = true', $html);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index'))
            ->assertOk();

        $this->actingAs($this->marketer)
            ->get(route('marketing.site-enrichment.index'))
            ->assertOk();
    }

    public function test_marketer_nav_excludes_ratings_community_activity(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('admin.site-ratings.index'), $html);
        $this->assertStringNotContainsString(route('admin.community.index'), $html);
        $this->assertStringNotContainsString(route('admin.activity-logs.index'), $html);
        $this->assertStringContainsString(route('marketing.sites.index'), $html);
        $this->assertStringContainsString(route('marketing.bulk-site-requests.index'), $html);
        $this->assertStringContainsString(route('marketing.site-enrichment.index'), $html);
    }
}
