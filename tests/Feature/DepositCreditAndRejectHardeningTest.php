<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletStripeDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DepositCreditAndRejectHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function walletFor(User $user): Wallet
    {
        $roleId = Wallet::advertiserRoleId();

        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    public function test_checkout_session_credit_refuses_order_payment_metadata(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $session = (object) [
            'id' => 'cs_order_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 11500,
            'payment_intent' => 'pi_order_'.uniqid(),
            'metadata' => (object) [
                'type' => 'order_payment',
                'user_id' => (string) $advertiser->id,
                'amount' => '115.00',
                'reference_code' => 'ORD-REF-1',
            ],
        ];

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);

        $this->assertSame(0.0, $credited);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('deposit_requests', 0);
    }

    public function test_checkout_session_credit_accepts_wallet_deposit_metadata(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $sessionId = 'cs_wallet_'.uniqid();

        $session = (object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'payment_intent' => 'pi_wallet_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '50.00',
                'reference_code' => 'DEP-OK-50',
            ],
        ];

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);

        $this->assertSame(50.0, $credited);
        $this->assertSame(50.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('deposit_requests', [
            'stripe_session_id' => $sessionId,
            'status' => 'completed',
            'amount' => 50,
        ]);
    }

    public function test_payment_intent_object_credit_refuses_order_payment_metadata(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $intent = (object) [
            'id' => 'pi_order_'.uniqid(),
            'status' => 'succeeded',
            'amount' => 11500,
            'amount_received' => 11500,
            'metadata' => (object) [
                'type' => 'order_payment',
                'user_id' => (string) $advertiser->id,
                'amount' => '115.00',
                'reference_code' => 'ORD-PI-1',
            ],
        ];

        $credited = app(WalletStripeDepositService::class)->creditFromPaymentIntentObject($intent);

        $this->assertSame(0.0, $credited);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('deposit_requests', 0);
    }

    public function test_admin_reject_cannot_overwrite_approved_deposit(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-RACE-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $deposit->fresh()->status);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $deposit->id), [
                'admin_notes' => 'Too late — already credited.',
            ])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This deposit request has already been processed.');

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertNull($deposit->fresh()->rejected_at);
    }

    public function test_admin_reject_still_works_for_pending_deposits(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-REJ-1',
            'amount' => 25,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $deposit->id), [
                'admin_notes' => 'No transfer found.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('rejected', $deposit->fresh()->status);
        $this->assertNotNull($deposit->fresh()->rejected_at);
    }
}
