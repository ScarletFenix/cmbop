<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPromotionsFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_expired_lists_only_expired_rows(): void
    {
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        SiteAnnouncement::create([
            'title' => 'Expired row',
            'message' => 'x',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->subDay(),
        ]);
        SiteAnnouncement::create([
            'title' => 'Live row',
            'message' => 'y',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.promotions.announcements.index', ['status' => 'expired']))
            ->assertOk()
            ->assertSee('Expired row', false)
            ->assertDontSee('Live row', false);
    }

    public function test_active_scope_excludes_unparseable_ends_at(): void
    {
        $live = SiteAnnouncement::create([
            'title' => 'Really live sql',
            'message' => 'y',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);
        $broken = SiteAnnouncement::create([
            'title' => 'Broken ends sql',
            'message' => 'x',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->addDay(),
        ]);
        DB::table('site_announcements')->where('id', $broken->id)->update([
            'ends_at' => 'not-a-date',
        ]);

        $ids = SiteAnnouncement::query()->active()->pluck('id');

        $this->assertTrue($ids->contains($live->id));
        $this->assertFalse($ids->contains($broken->id));
        $this->assertFalse($broken->fresh()->isCurrentlyLive());
    }

    public function test_status_live_excludes_unparseable_schedule_rows(): void
    {
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        SiteAnnouncement::create([
            'title' => 'Really live',
            'message' => 'y',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);
        $broken = SiteAnnouncement::create([
            'title' => 'Broken schedule',
            'message' => 'x',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->addDay(),
        ]);
        DB::table('site_announcements')->where('id', $broken->id)->update([
            'ends_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.promotions.announcements.index', ['status' => 'live']))
            ->assertOk()
            ->assertSee('Really live', false)
            ->assertDontSee('Broken schedule', false);
    }

    public function test_status_scheduled_and_expired_exclude_unparseable_dates(): void
    {
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        $scheduled = SiteAnnouncement::create([
            'title' => 'Really scheduled',
            'message' => 'y',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'starts_at' => now()->addDay(),
        ]);
        $brokenStarts = SiteAnnouncement::create([
            'title' => 'Garbage starts',
            'message' => 'x',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'starts_at' => now()->addDay(),
        ]);
        $expired = SiteAnnouncement::create([
            'title' => 'Really expired',
            'message' => 'z',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->subDay(),
        ]);
        $brokenEnds = SiteAnnouncement::create([
            'title' => 'Garbage ends',
            'message' => 'w',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->subDay(),
        ]);

        DB::table('site_announcements')->where('id', $brokenStarts->id)->update([
            'starts_at' => 'not-a-date',
        ]);
        DB::table('site_announcements')->where('id', $brokenEnds->id)->update([
            'ends_at' => 'not-a-date',
        ]);

        $this->assertSame('scheduled', $scheduled->fresh()->scheduleState());
        $this->assertSame('paused', $brokenStarts->fresh()->scheduleState());
        $this->assertSame('expired', $expired->fresh()->scheduleState());
        $this->assertSame('paused', $brokenEnds->fresh()->scheduleState());

        $this->actingAs($admin)
            ->get(route('admin.promotions.announcements.index', ['status' => 'scheduled']))
            ->assertOk()
            ->assertSee('Really scheduled', false)
            ->assertDontSee('Garbage starts', false);

        $this->actingAs($admin)
            ->get(route('admin.promotions.announcements.index', ['status' => 'expired']))
            ->assertOk()
            ->assertSee('Really expired', false)
            ->assertDontSee('Garbage ends', false);
    }
}
