<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersManageActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function adminUser(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Admin Operator',
            'email' => 'admin.operator@example.com',
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_users_page_keeps_manage_menu_unclipped_and_columns_readable(): void
    {
        $advertiser = Role::where('name', 'advertiser')->firstOrFail();
        $member = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
            'name' => 'Readable Name Here',
            'email' => 'readable.user@example.com',
            'phone' => '+32123456789',
            'country' => 'BE',
        ]);
        $member->roles()->attach($advertiser->id);

        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('User Management', false)
            ->assertSee('admin-manage-dropdown', false)
            ->assertSee('admin-manage-dropdown.js', false)
            ->assertSee('admin-col-email', false)
            ->assertSee('admin-role-badges', false)
            ->assertSee('readable.user@example.com', false)
            ->assertSee('Readable Name Here', false)
            ->assertSee('Manage', false)
            ->assertSee('action-view', false)
            ->assertSee('action-roles', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/\.modern-table\s*\{[^}]*overflow:\s*hidden/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.modern-table\s*\{[^}]*text-align:\s*center/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/\.modern-table\s*\{[^}]*overflow:\s*visible/s',
            $html
        );
    }
}
