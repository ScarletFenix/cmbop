<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 visibility contract:
 *   outside hide mode → always real name / host / rooted URL
 *   inside hide mode  → dual-mask until eye reveal
 */
class SiteUrlVisibilityHideModePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(array $attrs = []): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function site(string $domain = 'policy-host.example', string $name = 'Policy Host Brand'): Site
    {
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $publisher->roles()->attach($pubRole->id);

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain.'/deep/path',
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Visibility policy fixture.',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_outside_hide_mode_normals_see_full_identity_without_reveal(): void
    {
        $user = $this->advertiser();
        $site = $this->site();
        $visibility = app(SiteUrlVisibility::class);

        $this->assertFalse($visibility->inHideMode($user));
        $this->assertTrue($visibility->canSee($user, $site));
        $this->assertTrue($visibility->showsFullIdentity($user, $site));
        $this->assertSame('Policy Host Brand', $visibility->nameFor($user, $site));
        $this->assertSame('policy-host.example', $visibility->hostFor($user, $site));
        $this->assertSame('https://policy-host.example', $visibility->rootedUrlFor($user, $site));
    }

    public function test_inside_hide_mode_masks_until_reveal(): void
    {
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);
        $site = $this->site('hidden-policy.example', 'Hidden Policy Brand');
        $visibility = app(SiteUrlVisibility::class);

        $this->assertTrue($visibility->inHideMode($user));
        $this->assertFalse($visibility->canSee($user, $site));
        $this->assertFalse($visibility->showsFullIdentity($user, $site));
        $this->assertSame($visibility->maskName('Hidden Policy Brand'), $visibility->nameFor($user, $site));
        $this->assertSame($visibility->mask('https://hidden-policy.example'), $visibility->hostFor($user, $site));
        $this->assertStringContainsString('***', $visibility->rootedUrlFor($user, $site));
        $this->assertStringNotContainsString('hidden-policy.example', $visibility->rootedUrlFor($user, $site));

        $visibility->reveal($user, $site);
        $visibility->flush();

        $this->assertTrue($visibility->canSee($user->fresh(), $site));
        $this->assertTrue($visibility->showsFullIdentity($user->fresh(), $site));
        $this->assertSame('Hidden Policy Brand', $visibility->nameFor($user->fresh(), $site));
        $this->assertSame('hidden-policy.example', $visibility->hostFor($user->fresh(), $site));
        $this->assertSame('https://hidden-policy.example', $visibility->rootedUrlFor($user->fresh(), $site));
    }

    public function test_guests_do_not_get_full_identity_helpers(): void
    {
        $site = $this->site('guest-masked.example', 'Guest Masked Brand');
        $visibility = app(SiteUrlVisibility::class);

        $this->assertFalse($visibility->showsFullIdentity(null, $site));
        $this->assertFalse($visibility->canSee(null, $site));
        $this->assertSame($visibility->maskName('Guest Masked Brand'), $visibility->nameFor(null, $site));
        $this->assertSame($visibility->mask('https://guest-masked.example'), $visibility->hostFor(null, $site));
    }
}
