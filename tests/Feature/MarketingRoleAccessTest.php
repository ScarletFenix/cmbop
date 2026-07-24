<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRoles(array $roleNames, ?string $active = null): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
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

    public function test_granting_marketing_activates_marketing_workspace(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $member = $this->userWithRoles(['advertiser', 'publisher'], 'advertiser');

        $response = $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('marketing', true)
            ->assertJsonPath('active_role', 'marketing');

        $member->refresh();
        $this->assertTrue($member->hasRole('marketing'));
        $this->assertSame('marketing', $member->activeRole());
        $this->assertTrue($member->isMarketing());
    }

    public function test_marketing_user_can_open_admin_dashboard(): void
    {
        $marketer = $this->userWithRoles(['marketing', 'advertiser', 'publisher'], 'marketing');

        $this->actingAs($marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Marketing Dashboard', false);
    }

    public function test_user_with_three_roles_can_switch_to_marketing(): void
    {
        $user = $this->userWithRoles(['advertiser', 'publisher', 'marketing'], 'advertiser');
        $marketingId = Role::where('name', 'marketing')->value('id');

        $this->actingAs($user)
            ->post(route('switch.role'), ['active_role_id' => $marketingId])
            ->assertRedirect(route('marketing.dashboard'));

        $this->assertSame('marketing', $user->fresh()->activeRole());

        $this->actingAs($user->fresh())
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Marketing Dashboard', false);
    }

    public function test_advertiser_layout_lists_marketing_in_role_switch_dropdown(): void
    {
        $user = $this->userWithRoles(['advertiser', 'publisher', 'marketing'], 'advertiser');

        $html = $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Switch role', $html);
        $this->assertStringContainsString('Marketing', $html);
        $this->assertStringContainsString('Publisher', $html);
        // Must not only offer a single firstWhere role (Publisher) without Marketing.
        $this->assertStringContainsString((string) Role::where('name', 'marketing')->value('id'), $html);
    }

    public function test_revoking_marketing_falls_back_to_another_role(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $member = $this->userWithRoles(['advertiser', 'publisher', 'marketing'], 'marketing');

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => false,
            ])
            ->assertOk()
            ->assertJsonPath('marketing', false);

        $member->refresh();
        $this->assertFalse($member->hasRole('marketing'));
        $this->assertNotSame('marketing', $member->activeRole());
        $this->assertContains($member->activeRole(), ['advertiser', 'publisher']);
    }

    public function test_grant_creates_marketing_role_when_missing(): void
    {
        Role::where('name', 'marketing')->delete();
        $this->assertNull(Role::where('name', 'marketing')->value('id'));

        $admin = $this->userWithRoles(['admin'], 'admin');
        $member = $this->userWithRoles(['advertiser', 'publisher'], 'advertiser');

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active_role', 'marketing');

        $this->assertNotNull(Role::where('name', 'marketing')->value('id'));
        $this->assertTrue($member->fresh()->hasRole('marketing'));
    }

    public function test_marketing_attached_but_inactive_can_open_admin_dashboard(): void
    {
        // Common production case: role was granted but active_role stayed Advertiser.
        $marketer = $this->userWithRoles(['marketing', 'advertiser', 'publisher'], 'advertiser');

        $this->actingAs($marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Marketing Dashboard', false);

        $this->assertSame('marketing', $marketer->fresh()->activeRole());
    }
}
