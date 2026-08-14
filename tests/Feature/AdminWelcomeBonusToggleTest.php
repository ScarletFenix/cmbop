<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Wallet\WelcomeBonusService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWelcomeBonusToggleTest extends TestCase
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
    }

    public function test_guest_cannot_toggle_welcome_bonus(): void
    {
        $this->post(route('admin.promotions.welcome-bonus.toggle'))
            ->assertRedirect();

        $this->assertTrue(app(WelcomeBonusService::class)->isEnabled());
    }

    public function test_non_admin_cannot_toggle_welcome_bonus(): void
    {
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $user->roles()->attach($advertiserRole->id);

        $this->actingAs($user)
            ->post(route('admin.promotions.welcome-bonus.toggle'))
            ->assertForbidden();

        $this->assertTrue(app(WelcomeBonusService::class)->isEnabled());
    }

    public function test_admin_can_disable_and_enable_welcome_bonus(): void
    {
        $service = app(WelcomeBonusService::class);
        $this->assertTrue($service->isEnabled());

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'))
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertFalse($service->isEnabled());

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'))
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertTrue($service->isEnabled());
    }

    public function test_promotions_hub_shows_welcome_bonus_card(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('€20 welcome credit', false)
            ->assertSee('Enabled', false)
            ->assertSee('Disable', false);
    }
}
