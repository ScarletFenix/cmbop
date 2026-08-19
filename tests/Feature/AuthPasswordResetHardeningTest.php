<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\UserMessages;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthPasswordResetHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_forgot_and_reset_posts_are_rate_limited(): void
    {
        foreach (['password.email', 'password.update'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);

            $hasThrottle = collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

            $this->assertTrue($hasThrottle, $name.' must be throttled');
        }
    }

    public function test_weak_reset_password_is_rejected(): void
    {
        $this->postJson(route('password.update'), [
            'token' => 'not-a-real-token',
            'email' => 'nobody@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_sixth_forgot_password_is_too_many_attempts(): void
    {
        $payload = ['email' => 'nobody@example.com'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('password.email'), $payload)
                ->assertOk()
                ->assertJsonPath('status', 'success');
        }

        $this->postJson(route('password.email'), $payload)
            ->assertStatus(429)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', UserMessages::get('password.throttled'))
            ->assertHeader('Retry-After');
    }

    public function test_reset_assigns_plain_password_without_double_hash(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'reset-once@example.com',
            'email_verified_at' => now(),
            'password' => 'old-password',
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $token = Password::broker()->createToken($user);

        $this->postJson(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'newpass99',
            'password_confirmation' => 'newpass99',
        ])->assertOk()->assertJsonPath('status', 'success');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('newpass99', $fresh->password));
        $this->assertFalse(Hash::check('old-password', $fresh->password));
    }
}
