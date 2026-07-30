<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketingActivateSitesPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function userWithRoles(array $roleNames, ?string $active = null, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['email_verified_at' => now()], $attrs));
        $ids = [];
        foreach ($roleNames as $name) {
            $ids[$name] = Role::where('name', $name)->value('id');
            $user->roles()->attach($ids[$name]);
        }
        $activeName = $active ?? $roleNames[0];
        $user->active_role_id = $ids[$activeName];
        $user->save();

        return $user->fresh(['roles']);
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Ready Site',
            'site_url' => 'https://ready-site.example',
            'domain' => 'ready-site.example',
            'example_url' => 'https://ready-site.example/sample',
            'da' => 30,
            'dr' => 30,
            'traffic' => 5000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Ready for admin activation description. ', 2),
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ], $overrides));
    }

    public function test_admin_can_grant_and_revoke_activate_sites_permission(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $member = $this->userWithRoles(['advertiser', 'publisher'], 'advertiser');

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => true,
                'can_activate_sites' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('marketing', true)
            ->assertJsonPath('can_activate_sites', true);

        $member->refresh();
        $this->assertTrue($member->hasRole('marketing'));
        $this->assertTrue((bool) $member->can_activate_sites);
        $this->assertTrue($member->fresh()->canActivateSites());

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => true,
                'can_activate_sites' => false,
            ])
            ->assertOk()
            ->assertJsonPath('can_activate_sites', false);

        $this->assertFalse((bool) $member->fresh()->can_activate_sites);

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => false,
                'can_activate_sites' => true,
            ])
            ->assertOk()
            ->assertJsonPath('marketing', false)
            ->assertJsonPath('can_activate_sites', false);

        $this->assertFalse($member->fresh()->hasRole('marketing'));
        $this->assertFalse((bool) $member->fresh()->can_activate_sites);
    }

    public function test_marketer_without_permission_cannot_activate(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => false]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertForbidden();

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_marketer_with_permission_can_activate_ready_site(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher);

        $this->assertTrue($marketer->canActivateSites());

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_marketer_with_permission_can_activate_completed_bulk_site(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_name' => 'Bulk Ready',
            'site_url' => 'https://bulk-ready.example',
            'domain' => 'bulk-ready.example',
            // bulk_site_request_id left null — FK not needed; status mirrors post-publisher completion.
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_marketer_cannot_activate_awaiting_details_site(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_name' => 'Incomplete Draft',
            'site_url' => 'https://incomplete-draft.example',
            'domain' => 'incomplete-draft.example',
            'description' => 'Please replace this placeholder with a real site description (at least 50 characters) before submitting for review.',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'turnaround_time' => '3days',
        ]);

        $this->assertTrue($site->awaitsPublisherDetails());

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $site->fresh()->active);
        $this->assertTrue($site->fresh()->awaitsPublisherDetails());
    }

    public function test_admin_can_still_activate_awaiting_details_site(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue((bool) $site->active);
        $this->assertFalse($site->awaitsPublisherDetails());
    }

    public function test_marketer_with_permission_can_deactivate(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, ['active' => true]);

        $this->actingAs($marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 0])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_users_page_exposes_activate_permission_toggle(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $marketer = $this->userWithRoles(['marketing'], 'marketing', ['can_activate_sites' => true]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('data-can-activate-sites="1"', false)
            ->assertSee('Activate sites', false);
    }
}
