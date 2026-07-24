<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherTasksBadgeUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_publisher_tasks_nav_badge_matches_bell_count_ui(): void
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $html = $this->actingAs($user)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="navNeedsActionBadge"', $html);
        $this->assertStringContainsString('nc-nav-badge', $html);
        $this->assertStringContainsString('data-pulse-display="inline-flex"', $html);
        $this->assertStringContainsString('alertOnIncrease: true', $html);
        $this->assertStringNotContainsString('nav-alert-badge pulse-badge rounded-pill ms-auto', $html);

        $css = file_get_contents(public_path('css/app-shell.css'));
        $this->assertStringContainsString('#sidebar .nc-nav-badge', $css);
        $this->assertStringContainsString('var(--nc-danger, #dc3545)', $css);
    }
}
