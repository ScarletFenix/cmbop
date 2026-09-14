<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TawkChatWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $name): User
    {
        $role = Role::firstOrCreate(['name' => $name]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_public_home_embeds_tawk_and_hides_help_fab_when_configured(): void
    {
        config([
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => 'default',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('https://embed.tawk.to/6aa6a3693d02a53444168308/default', false)
            ->assertSee('Tawk_API', false)
            ->assertDontSee('aria-label="Open help and feedback"', false)
            ->assertSee('slbOpenSupport', false)
            ->assertDontSee("helpFeedbackToggle')?.click()", false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_public_home_keeps_help_fab_when_tawk_is_off(): void
    {
        config([
            'services.tawk.property_id' => '',
            'services.tawk.widget_id' => '',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('aria-label="Open help and feedback"', false)
            ->assertDontSee('embed.tawk.to', false);
    }

    public function test_public_and_portal_layouts_include_tawk_partial(): void
    {
        foreach ([
            resource_path('views/layouts/app.blade.php'),
            resource_path('views/advertiser/layouts/app.blade.php'),
            resource_path('views/publisher/layouts/app.blade.php'),
        ] as $path) {
            $layout = (string) file_get_contents($path);
            $this->assertStringContainsString('partials.tawk', $layout);
            $this->assertStringContainsString('TawkChat::enabled()', $layout);
        }

        $admin = (string) file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $this->assertStringNotContainsString('partials.tawk', $admin);
    }

    public function test_advertiser_and_publisher_dashboards_embed_tawk_when_configured(): void
    {
        config([
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => 'default',
        ]);

        $advertiser = $this->userWithRole('advertiser');
        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('https://embed.tawk.to/6aa6a3693d02a53444168308/default', false)
            ->assertSee('Tawk_API.visitor', false)
            ->assertSee($advertiser->email, false)
            ->assertSee('slbOpenSupport', false)
            ->assertDontSee('aria-label="Open help and feedback"', false);

        $publisher = $this->userWithRole('publisher');
        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee('https://embed.tawk.to/6aa6a3693d02a53444168308/default', false)
            ->assertSee('Tawk_API.visitor', false)
            ->assertSee($publisher->email, false)
            ->assertDontSee('aria-label="Open help and feedback"', false);
    }
}
