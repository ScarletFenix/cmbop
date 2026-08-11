<?php

namespace Tests\Feature;

use App\Mail\WithdrawalStatusUpdated;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\ManualWithdrawalInvalidTransitionException;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ManualWithdrawalSettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisherWallet(User $user, float $balance = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function pendingWithdrawal(User $user, float $amount = 100): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'fee' => 0,
            'net_amount' => $amount,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ]);
    }

    public function test_mark_paid_completes_pending_withdrawal(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 75);

        $result = app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin, 'Paid via Wise');

        $this->assertFalse($result['unchanged']);
        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertSame('Paid via Wise', $withdrawal->fresh()->admin_notes);
        $this->assertNotNull($withdrawal->fresh()->processed_at);
        Mail::assertQueued(WithdrawalStatusUpdated::class, 1);
    }

    public function test_reject_refunds_publisher_wallet(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 40);

        app(ManualWithdrawalSettlementService::class)->reject($withdrawal, $admin, 'Bad IBAN');

        $this->assertSame('cancelled', $withdrawal->fresh()->status);
        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
    }

    public function test_double_mark_paid_is_unchanged(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 50);

        $service = app(ManualWithdrawalSettlementService::class);
        $service->markPaid($withdrawal, $admin);
        $second = $service->markPaid($withdrawal->fresh(), $admin);

        $this->assertTrue($second['unchanged']);
        $this->assertSame('completed', $withdrawal->fresh()->status);
        Mail::assertQueued(WithdrawalStatusUpdated::class, 1);
    }

    public function test_cannot_reject_completed_withdrawal(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 30);

        $service = app(ManualWithdrawalSettlementService::class);
        $service->markPaid($withdrawal, $admin);

        $this->expectException(ManualWithdrawalInvalidTransitionException::class);
        $service->reject($withdrawal->fresh(), $admin);

        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
    }

    public function test_admin_http_mark_paid_uses_service(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 60);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.paid', $withdrawal->id), [
                'notes' => 'Sent',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $withdrawal->fresh()->status);
    }
}
