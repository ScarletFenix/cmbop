<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleSwitchUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRoles(array $roleNames, string $active): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $ids = [];
        foreach ($roleNames as $name) {
            $ids[$name] = Role::where('name', $name)->value('id');
            $user->roles()->attach($ids[$name]);
        }
        $user->active_role_id = $ids[$active];
        $user->save();

        return $user->fresh(['roles']);
    }

    public function test_role_switch_dropdown_uses_fixed_popper_to_escape_topbar_clip(): void
    {
        $blade = file_get_contents(resource_path('views/partials/role-switcher.blade.php'));
        $this->assertStringContainsString('role-switch-dropdown', $blade);
        $this->assertStringContainsString('data-bs-popper-config', $blade);
        $this->assertStringContainsString('"strategy":"fixed"', $blade);
    }

    public function test_multi_role_advertiser_sees_working_switch_markup(): void
    {
        $user = $this->userWithRoles(['advertiser', 'publisher', 'marketing'], 'advertiser');

        $html = $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Switch role', false)
            ->assertSee('data-bs-popper-config', false)
            ->assertSee('role-switch.js', false)
            ->getContent();

        $this->assertStringContainsString(route('switch.role', absolute: false), $html);
        $this->assertStringContainsString((string) Role::where('name', 'marketing')->value('id'), $html);
        $this->assertStringContainsString((string) Role::where('name', 'publisher')->value('id'), $html);
        $this->assertStringContainsString('Marketing workspace · site review', $html);
        $this->assertStringNotContainsString('Admin panel · site review', $html);
    }

    public function test_switching_from_marketing_to_advertiser_lands_on_advertiser_dashboard(): void
    {
        $user = $this->userWithRoles(['advertiser', 'publisher', 'marketing'], 'marketing');
        $advertiserId = Role::where('name', 'advertiser')->value('id');

        $this->actingAs($user)
            ->post(route('switch.role'), ['active_role_id' => $advertiserId])
            ->assertRedirect(route('advertiser.dashboard'));

        $this->assertSame('advertiser', $user->fresh()->activeRole());
    }

    public function test_admin_and_marketing_layouts_include_mobile_role_switch(): void
    {
        $admin = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $marketing = file_get_contents(resource_path('views/marketing/layouts/app.blade.php'));

        $this->assertStringContainsString('d-md-none', $admin);
        $this->assertStringContainsString('partials.role-switcher', $admin);
        $this->assertStringContainsString('d-md-none', $marketing);
        $this->assertStringContainsString('partials.role-switcher', $marketing);
    }
}
