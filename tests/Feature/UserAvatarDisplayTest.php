<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAvatarDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName, array $attrs = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Avatar User',
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh(['roles']);
    }

    public function test_marketing_layout_falls_back_when_avatar_missing(): void
    {
        $marketer = $this->userWithRole('marketing', ['avatar' => null]);

        $html = $this->actingAs($marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('lh3.googleusercontent.com', $html);
        $this->assertStringContainsString('background: #5bc4c7', $html);
        $this->assertMatchesRegularExpression('/>\s*A\s*<\/div>/', $html);
    }

    public function test_marketing_layout_renders_avatar_with_onerror_fallback(): void
    {
        $marketer = $this->userWithRole('marketing', [
            'avatar' => 'https://lh3.googleusercontent.com/a/example-avatar',
        ]);

        $html = $this->actingAs($marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('https://lh3.googleusercontent.com/a/example-avatar', $html);
        $this->assertStringContainsString('onerror=', $html);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $html);
    }

    public function test_marketing_sites_page_exposes_activate_toggle_like_admin(): void
    {
        $marketer = $this->userWithRole('marketing', ['can_activate_sites' => false]);

        $html = $this->actingAs($marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('const CAN_TOGGLE_ACTIVE = true', $html);
        $this->assertStringContainsString('admin-manage-dropdown', $html);
        $this->assertStringContainsString('data-bs-popper-config', $html);
    }
}
