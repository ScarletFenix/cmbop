<?php

namespace Tests\Feature;

use App\Models\CatalogCopyEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminCatalogCopyStrikeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    public function test_admin_catalog_activity_lists_hide_mode_and_warned_accounts(): void
    {
        $admin = $this->userWithRole('admin');

        $hidden = $this->userWithRole('advertiser');
        $hidden->forceFill([
            'email' => 'hidden-copy@example.com',
            'catalog_copy_strike_count' => 2,
            'catalog_copy_warned_at' => now()->subHour(),
            'catalog_hide_until' => now()->addHours(20),
        ])->save();

        $warned = $this->userWithRole('advertiser');
        $warned->forceFill([
            'email' => 'warned-copy@example.com',
            'catalog_copy_strike_count' => 1,
            'catalog_copy_warned_at' => now()->subMinutes(30),
        ])->save();

        $clean = $this->userWithRole('advertiser');
        $clean->forceFill(['email' => 'clean-copy@example.com'])->save();

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity'))
            ->assertOk()
            ->assertSee('Copy strikes')
            ->assertSee('hidden-copy@example.com')
            ->assertSee('warned-copy@example.com')
            ->assertSee('Hide mode 24h')
            ->assertSee('Warned')
            ->assertSee('Clear hide mode')
            ->assertDontSee('clean-copy@example.com');
    }

    public function test_leftover_hide_until_is_not_listed_as_hide_mode(): void
    {
        $admin = $this->userWithRole('admin');

        $real = $this->userWithRole('advertiser');
        $real->forceFill([
            'email' => 'real-hide@example.com',
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addHours(20),
        ])->save();

        $leftover = $this->userWithRole('advertiser');
        $leftover->forceFill([
            'email' => 'garbage-hide@example.com',
            'catalog_copy_strike_count' => 0,
            'catalog_hide_until' => now()->addDay(),
        ])->save();
        DB::table('users')->where('id', $leftover->id)->update([
            'catalog_hide_until' => 'not-a-date',
        ]);

        $this->assertFalse($leftover->fresh()->inCatalogHideMode());

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity'))
            ->assertOk()
            ->assertSee('real-hide@example.com')
            ->assertDontSee('garbage-hide@example.com')
            ->assertDontSee('Something went wrong');
    }

    public function test_admin_can_clear_copy_hide_mode_and_reset_strikes(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_copy_warned_at' => now()->subHour(),
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        CatalogCopyEvent::create([
            'user_id' => $advertiser->id,
            'site_id' => null,
            'normalized_host' => 'copied.example',
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.catalog-activity.clear-copy-hide', $advertiser->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $advertiser->refresh();
        $this->assertSame(0, (int) $advertiser->catalog_copy_strike_count);
        $this->assertNull($advertiser->catalog_copy_warned_at);
        $this->assertNull($advertiser->catalog_hide_until);
        $this->assertFalse($advertiser->inCatalogHideMode());
        $this->assertSame(0, CatalogCopyEvent::where('user_id', $advertiser->id)->count());
    }

    public function test_non_admin_cannot_clear_copy_hide_mode(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        $this->actingAs($advertiser)
            ->post(route('admin.catalog-activity.clear-copy-hide', $advertiser->id))
            ->assertStatus(403);

        $advertiser->refresh();
        $this->assertTrue($advertiser->inCatalogHideMode());
        $this->assertSame(2, (int) $advertiser->catalog_copy_strike_count);
    }
}
