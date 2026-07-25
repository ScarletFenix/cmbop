<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
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

    private function mockSocialUser(string $id, string $email, string $name = 'Google User'): SocialiteUser
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($id);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->token = 'access-token';
        $socialUser->refreshToken = 'refresh-token';

        return $socialUser;
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

    public function test_google_callback_logs_in_existing_user_to_dashboard(): void
    {
        $this->configureGoogle();

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'google-user@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $socialUser = $this->mockSocialUser('google-oid-99', 'google-user@example.com');
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('advertiser.dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-oid-99', $user->fresh()->google_id);
    }

    public function test_google_callback_creates_new_user_and_redirects_to_dashboard(): void
    {
        $this->configureGoogle();

        $socialUser = $this->mockSocialUser('google-new-42', 'new-google@example.com', 'New Google');
        $socialUser->refreshToken = null;

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('advertiser.dashboard'));

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

    public function test_google_callback_ignores_login_as_intended_url(): void
    {
        $this->configureGoogle();

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'intended-login@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $socialUser = $this->mockSocialUser('google-intended-1', 'intended-login@example.com');
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->withSession(['url.intended' => url('/login')])
            ->get(route('auth.google.callback'))
            ->assertRedirect(route('advertiser.dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_google_callback_retries_stateless_after_invalid_state(): void
    {
        $this->configureGoogle();

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'stateless-google@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $socialUser = $this->mockSocialUser('google-stateless-7', 'stateless-google@example.com');

        $statelessProvider = Mockery::mock(Provider::class);
        $statelessProvider->shouldReceive('user')->once()->andReturn($socialUser);

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->once()->andThrow(new InvalidStateException);
        $driver->shouldReceive('stateless')->once()->andReturn($statelessProvider);

        // First driver('google') call uses stateful user(); catch retries with stateless().
        Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($driver);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('advertiser.dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_authenticated_user_visiting_login_goes_to_dashboard(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->get(route('login'))
            ->assertRedirect(route('advertiser.dashboard'));
    }
}
