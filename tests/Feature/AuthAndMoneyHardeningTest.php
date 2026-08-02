<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Security\RecaptchaVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthAndMoneyHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function verifier(): RecaptchaVerifier
    {
        return app(RecaptchaVerifier::class);
    }

    public function test_recaptcha_is_skipped_when_no_secret_is_configured(): void
    {
        config(['services.recaptcha.secret_key' => '']);
        Http::fake();

        $this->assertFalse($this->verifier()->configured());
        $this->assertTrue($this->verifier()->verify(''));

        Http::assertNothingSent();
    }

    public function test_recaptcha_rejects_a_missing_token_when_configured(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake();

        $this->assertFalse($this->verifier()->verify(''));

        Http::assertNothingSent();
    }

    public function test_recaptcha_rejects_a_token_google_marks_invalid(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake([
            '*siteverify*' => Http::response(['success' => false], 200),
        ]);

        $this->assertFalse($this->verifier()->verify('bad-token'));
    }

    public function test_recaptcha_accepts_a_token_google_marks_valid(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake([
            '*siteverify*' => Http::response(['success' => true], 200),
        ]);

        $this->assertTrue($this->verifier()->verify('good-token'));
    }

    public function test_recaptcha_outage_does_not_lock_users_out(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake([
            '*siteverify*' => Http::response('gateway down', 502),
        ]);

        $this->assertTrue($this->verifier()->verify('any-token'));
    }

    public function test_login_is_blocked_when_captcha_fails(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake([
            '*siteverify*' => Http::response(['success' => false], 200),
        ]);

        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('secret-password'),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
            'g-recaptcha-response' => 'bad',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_forgot_password_is_blocked_when_captcha_fails(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake([
            '*siteverify*' => Http::response(['success' => false], 200),
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'nobody@example.com',
            'g-recaptcha-response' => 'bad',
        ])->assertStatus(422);
    }

    public function test_cron_auto_approve_is_disabled_without_a_strong_secret(): void
    {
        config(['app.cron_secret' => 'short']);

        $this->get('/cron/orders-auto-approve/short')->assertNotFound();
    }

    public function test_cron_auto_approve_rejects_a_wrong_secret(): void
    {
        config(['app.cron_secret' => str_repeat('a', 40)]);

        $this->get('/cron/orders-auto-approve/'.str_repeat('b', 40))->assertForbidden();
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function throttledMoneyRoutes(): array
    {
        return [
            ['advertiser.add-funds.store'],
            ['advertiser.create-checkout-session'],
            ['advertiser.add-funds.pay-saved-card'],
            ['advertiser.create-order-payment'],
            ['advertiser.balance.withdraw'],
            ['publisher.withdraw.request'],
        ];
    }

    #[DataProvider('throttledMoneyRoutes')]
    public function test_money_endpoints_are_rate_limited(string $routeName): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} is missing");

        $hasThrottle = collect($route->gatherMiddleware())
            ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        $this->assertTrue($hasThrottle, "Route {$routeName} must be throttled");
    }

    public function test_privileged_user_columns_are_not_mass_assignable(): void
    {
        $user = new User;

        $this->assertFalse($user->isFillable('email_verified_at'));
        $this->assertFalse($user->isFillable('can_activate_sites'));

        $created = User::create([
            'name' => 'Mass Assign',
            'email' => 'mass-assign@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
            'can_activate_sites' => true,
        ]);

        $this->assertNull($created->fresh()->email_verified_at);
        $this->assertFalse((bool) $created->fresh()->can_activate_sites);
    }
}
