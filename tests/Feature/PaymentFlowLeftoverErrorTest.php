<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentFlowLeftoverErrorTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_payment_leftover';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        config([
            'services.paypal.client_id' => 'leftover-paypal-client',
            'services.paypal.secret' => 'leftover-paypal-secret',
            'services.stripe.webhook_secret' => $this->webhookSecret,
        ]);
    }

    private function advertiser(): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_paypal_return_and_cancel_survive_dropped_orders_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.return', [
                'ref' => 'PP-LEFT',
                'token' => 'PO-LEFT',
            ]))
            ->assertRedirect(route('advertiser.checkout'));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout.paypal.cancel', ['ref' => 'PP-LEFT']))
            ->assertRedirect();
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_orders_pay_again_cancel_survives_dropped_orders_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->get(route('advertiser.orders', ['retry' => 'canceled', 'ref' => 'CARD-LEFT']))
            ->assertOk()
            ->assertDontSee('SQLSTATE');
    }

    public function test_invoice_survives_dropped_deposit_and_order_tables(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('deposit_requests');
        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->get(route('advertiser.invoice', 'REF-LEFT'))
            ->assertRedirect();
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_stripe_webhook_survives_dropped_log_table(): void
    {
        Schema::dropIfExists('stripe_webhook_logs');

        $event = [
            'id' => 'evt_leftover_log',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_leftover',
                    'object' => 'checkout.session',
                    'metadata' => [
                        'type' => 'wallet_deposit',
                        'user_id' => '1',
                    ],
                ],
            ],
        ];
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't='.$timestamp.',v1='.$signature,
            ],
            $payload
        )
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }
}
