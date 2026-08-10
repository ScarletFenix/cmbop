<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthAndMoneyHardeningTest extends TestCase
{
    use RefreshDatabase;

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
