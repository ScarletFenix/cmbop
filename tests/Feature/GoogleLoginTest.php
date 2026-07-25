<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-google-client-id',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    public function test_unconfigured_google_redirect_returns_to_login_with_error(): void
    {
        config([
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
        ]);

        $this->followingRedirects()
            ->get(route('auth.google'))
            ->assertOk()
            ->assertSee('Google sign-in is not configured', false);
    }

    public function test_login_hides_google_button_when_unconfigured(): void
    {
        config([
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Continue with Google', false)
            ->assertDontSee(route('auth.google', absolute: false), false);
    }

    public function test_login_shows_google_button_when_configured(): void
    {
        $this->configureGoogle();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google', false)
            ->assertSee(route('auth.google'), false);
    }

    public function test_google_callback_access_denied_returns_friendly_error(): void
    {
        $this->configureGoogle();

        $this->get(route('auth.google.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_google_callback_logs_in_existing_user(): void
    {
        $this->configureGoogle();

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'google-user@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn('google-oid-99');
        $socialUser->shouldReceive('getEmail')->andReturn('google-user@example.com');
        $socialUser->shouldReceive('getName')->andReturn('Google User');
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->token = 'access-token';
        $socialUser->refreshToken = 'refresh-token';

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))
            ->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-oid-99', $user->fresh()->google_id);
    }

    public function test_google_callback_creates_new_user_with_welcome_bonus(): void
    {
        $this->configureGoogle();

        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn('google-new-42');
        $socialUser->shouldReceive('getEmail')->andReturn('new-google@example.com');
        $socialUser->shouldReceive('getName')->andReturn('New Google');
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->token = 'access-token';
        $socialUser->refreshToken = null;

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))
            ->assertRedirect();

        $user = User::where('email', 'new-google@example.com')->first();
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-new-42', $user->google_id);
        $this->assertNotNull($user->email_verified_at);

        $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
        $wallet = $user->wallets()->where('role_id', $advertiserRoleId)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(20.0, (float) $wallet->bonus_balance);
    }
}
