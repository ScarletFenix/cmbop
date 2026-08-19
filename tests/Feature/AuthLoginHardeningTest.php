<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\UserMessages;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthLoginHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_login_post_is_rate_limited(): void
    {
        $route = Route::getRoutes()->getByName('login.post');

        $this->assertNotNull($route);
        $hasThrottle = collect($route->gatherMiddleware())
            ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        $this->assertTrue($hasThrottle, 'Route login.post must be throttled');
    }

    public function test_unverified_login_matches_wrong_password_json(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'still-unverified@example.com',
            'email_verified_at' => null,
            'password' => 'password',
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $wrong = $this->postJson(route('login.post'), [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);
        $unverified = $this->postJson(route('login.post'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $wrong->assertOk()->assertJsonPath('status', 'error');
        $unverified->assertOk()->assertJsonPath('status', 'error');
        $this->assertSame($wrong->json('status'), $unverified->json('status'));
        $this->assertSame($wrong->json('message'), $unverified->json('message'));
        $this->assertSame('Invalid email or password.', $unverified->json('message'));
        $this->assertArrayNotHasKey('email', $unverified->json());
        $this->assertGuest();
    }

    public function test_sixth_failed_login_is_too_many_attempts(): void
    {
        $payload = [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('login.post'), $payload)
                ->assertOk()
                ->assertJsonPath('status', 'error');
        }

        $this->postJson(route('login.post'), $payload)
            ->assertStatus(429)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', UserMessages::get('login.throttled'))
            ->assertHeader('Retry-After');
    }

    public function test_successful_login_regenerates_the_session(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'verified-login@example.com',
            'email_verified_at' => now(),
            'password' => 'password',
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->get(route('login'))->assertOk();
        $before = session()->getId();

        $this->postJson(route('login.post'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user);
        $this->assertAuthenticated();

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();

        $this->get('/advertiser/dashboard')->assertRedirect(route('login'));
    }

    public function test_expired_and_too_many_pages_use_everyday_language(): void
    {
        Route::middleware('web')->get('/__hardening/419', fn () => abort(419));
        Route::middleware('web')->get('/__hardening/429', fn () => abort(429));

        $this->get('/__hardening/419')
            ->assertStatus(419)
            ->assertSee('This page expired', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('vendor/', false);

        $this->get('/__hardening/429')
            ->assertStatus(429)
            ->assertSee('Too many attempts', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('vendor/', false);
    }
}
