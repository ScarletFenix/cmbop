<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\FeatureBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFundsPaymentUxTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    public function test_card_tile_uses_visa_mastercard_not_stripe_mark(): void
    {
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('assets/img/payments/visa.svg', false)
            ->assertSee('assets/img/payments/mastercard.svg', false)
            ->assertSee('assets/img/payments/paypal.svg', false)
            ->assertDontSee('fab fa-stripe', false)
            ->getContent();

        $this->assertStringContainsString('payment-brand-logos', $html);
        $this->assertStringContainsString('lastUsedMethod:', $html);
    }

    public function test_last_completed_deposit_comes_first_with_recently_used_and_prefill(): void
    {
        $user = $this->advertiser();

        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'LU001111',
            'amount' => 40,
            'payment_method' => 'card',
            'status' => 'completed',
        ]);
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'LU002222',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'approved',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('Recently used', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/payment-methods-row[\s\S]*?data-method="bank"[\s\S]*?data-method="card"/',
            $html
        );
        $this->assertStringContainsString('lastUsedMethod: "bank"', $html);
        $this->assertStringContainsString('prefillMethod: "bank"', $html);
    }

    public function test_query_method_overrides_prefill_but_last_used_still_sorts_first(): void
    {
        $user = $this->advertiser();

        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'LU003333',
            'amount' => 25,
            'payment_method' => 'wise',
            'status' => 'completed',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['method' => 'bank']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/payment-methods-row[\s\S]*?data-method="wise"[\s\S]*?data-method="bank"/',
            $html
        );
        $this->assertStringContainsString('lastUsedMethod: "wise"', $html);
        $this->assertStringContainsString('prefillMethod: "bank"', $html);
    }

    public function test_paypal_shows_new_badge_while_configured_window_is_open(): void
    {
        config([
            'feature_badges.add_funds.paypal' => [
                'label' => 'New',
                'until' => now()->addWeek()->toDateString(),
            ],
        ]);

        $this->assertTrue(FeatureBadge::active('add_funds.paypal'));

        $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('feature-new-badge', false)
            ->assertSee('>New</span>', false);
    }
}
