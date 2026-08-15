<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletBonusTest extends TestCase
{
    use RefreshDatabase;

    private function makeWallet(float $balance = 20, float $bonus = 20): Wallet
    {
        $role = Role::create(['name' => 'advertiser']);
        $user = User::factory()->create();

        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => $bonus,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    public function test_welcome_bonus_is_not_withdrawable(): void
    {
        $wallet = $this->makeWallet(20, 20);

        $this->assertSame(0.0, $wallet->withdrawableBalance());
        $this->assertSame(20.0, $wallet->lockedBonusBalance());
    }

    public function test_deposited_funds_are_withdrawable_while_bonus_stays_locked(): void
    {
        $wallet = $this->makeWallet(20, 20);
        $wallet->addBalance(50);

        $this->assertEquals(70.0, (float) $wallet->fresh()->balance);
        $this->assertSame(50.0, $wallet->fresh()->withdrawableBalance());
        $this->assertSame(20.0, $wallet->fresh()->lockedBonusBalance());
    }

    public function test_spending_consumes_bonus_first_when_enabled(): void
    {
        $wallet = $this->makeWallet(70, 20);

        $used = $wallet->reserveForOrder(30, true);

        $wallet->refresh();
        $this->assertSame(20.0, $used);
        $this->assertEquals(40.0, (float) $wallet->balance);
        $this->assertEquals(30.0, (float) $wallet->reserved_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_balance);
        $this->assertEquals(20.0, (float) $wallet->bonus_reserved);
        $this->assertSame(40.0, $wallet->withdrawableBalance());
    }

    public function test_spending_without_bonus_uses_cash_only(): void
    {
        $wallet = $this->makeWallet(70, 20);

        $used = $wallet->reserveForOrder(30, false);

        $wallet->refresh();
        $this->assertSame(0.0, $used);
        $this->assertEquals(40.0, (float) $wallet->balance);
        $this->assertEquals(30.0, (float) $wallet->reserved_balance);
        $this->assertEquals(20.0, (float) $wallet->bonus_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_reserved);
        $this->assertSame(20.0, $wallet->withdrawableBalance());
    }

    public function test_cannot_spend_cash_only_when_only_bonus_remains(): void
    {
        $wallet = $this->makeWallet(20, 20);

        $this->expectException(\Exception::class);
        $wallet->reserveForOrder(10, false);
    }

    public function test_repair_orphaned_welcome_bonus_from_ledger(): void
    {
        $wallet = $this->makeWallet(20, 0);
        DB::table('wallet_transactions')->insert([
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'type' => 'bonus_credit',
            'direction' => 'credit',
            'amount' => 20,
            'bonus_amount' => 20,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'Welcome promotional bonus',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($wallet->repairOrphanedWelcomeBonus());
        $wallet->refresh();
        $this->assertSame(20.0, (float) $wallet->bonus_balance);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_repair_does_not_retag_deposited_cash_after_welcome_is_spent(): void
    {
        $wallet = $this->makeWallet(100, 0);
        DB::table('wallet_transactions')->insert([
            [
                'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id,
                'type' => 'bonus_credit',
                'direction' => 'credit',
                'amount' => 20,
                'bonus_amount' => 20,
                'currency' => 'EUR',
                'status' => 'completed',
                'description' => 'Welcome promotional bonus',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id,
                'type' => 'purchase',
                'direction' => 'debit',
                'amount' => 20,
                'bonus_amount' => 20,
                'currency' => 'EUR',
                'status' => 'completed',
                'description' => 'Marketplace purchase',
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ],
            [
                'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id,
                'type' => 'deposit',
                'direction' => 'credit',
                'amount' => 100,
                'bonus_amount' => 0,
                'currency' => 'EUR',
                'status' => 'completed',
                'description' => 'Wallet deposit',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertFalse($wallet->repairOrphanedWelcomeBonus());
        $wallet->refresh();
        $this->assertSame(0.0, (float) $wallet->bonus_balance);
        $this->assertSame(100.0, $wallet->withdrawableBalance());
    }

    public function test_repair_tags_only_unspent_welcome_bonus(): void
    {
        $wallet = $this->makeWallet(90, 0);
        DB::table('wallet_transactions')->insert([
            [
                'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id,
                'type' => 'bonus_credit',
                'direction' => 'credit',
                'amount' => 20,
                'bonus_amount' => 20,
                'currency' => 'EUR',
                'status' => 'completed',
                'description' => 'Welcome promotional bonus',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id,
                'type' => 'purchase',
                'direction' => 'debit',
                'amount' => 10,
                'bonus_amount' => 10,
                'currency' => 'EUR',
                'status' => 'completed',
                'description' => 'Marketplace purchase',
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ],
        ]);

        $this->assertTrue($wallet->repairOrphanedWelcomeBonus());
        $wallet->refresh();
        $this->assertSame(10.0, (float) $wallet->bonus_balance);
        $this->assertSame(80.0, $wallet->withdrawableBalance());
    }

    public function test_reconcile_inflated_bonus_clamps_to_ledger_credits(): void
    {
        $wallet = $this->makeWallet(45, 45);
        DB::table('wallet_transactions')->insert([
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'type' => 'bonus_credit',
            'direction' => 'credit',
            'amount' => 20,
            'bonus_amount' => 20,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'Welcome promotional bonus',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($wallet->reconcileInflatedBonusBalance());
        $wallet->refresh();
        $this->assertSame(20.0, (float) $wallet->bonus_balance);
        $this->assertSame(20.0, $wallet->lockedBonusBalance());
        $this->assertSame(25.0, $wallet->withdrawableBalance());
    }

    public function test_refund_restores_spend_only_bonus(): void
    {
        $wallet = $this->makeWallet(20, 20);
        $wallet->reserveForOrder(20);
        $wallet->refundReserved(20);

        $wallet->refresh();
        $this->assertEquals(20.0, (float) $wallet->balance);
        $this->assertEquals(20.0, (float) $wallet->bonus_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_reserved);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_completed_order_permanently_consumes_bonus(): void
    {
        $wallet = $this->makeWallet(20, 20);
        $wallet->reserveForOrder(20);
        $wallet->consumeReserved(20);

        $wallet->refresh();
        $this->assertEquals(0.0, (float) $wallet->balance);
        $this->assertEquals(0.0, (float) $wallet->reserved_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_reserved);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_consume_reserved_honors_bonus_limit_for_sibling_share(): void
    {
        $wallet = $this->makeWallet(100, 20);
        $wallet->reserveForOrder(100);

        $wallet->consumeReserved(50, 10);

        $wallet->refresh();
        $this->assertEquals(0.0, (float) $wallet->balance);
        $this->assertEquals(50.0, (float) $wallet->reserved_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_balance);
        $this->assertEquals(10.0, (float) $wallet->bonus_reserved);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_refund_reserved_honors_bonus_limit_for_sibling_share(): void
    {
        $wallet = $this->makeWallet(100, 20);
        $wallet->reserveForOrder(100);

        $wallet->refundReserved(50, 10);

        $wallet->refresh();
        $this->assertEquals(50.0, (float) $wallet->balance);
        $this->assertEquals(50.0, (float) $wallet->reserved_balance);
        $this->assertEquals(10.0, (float) $wallet->bonus_balance);
        $this->assertEquals(10.0, (float) $wallet->bonus_reserved);
        $this->assertEquals(40.0, $wallet->withdrawableBalance());
    }

    public function test_cannot_deduct_bonus_via_withdrawable_helper(): void
    {
        $wallet = $this->makeWallet(20, 20);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Wallet::PROMOTIONAL_BONUS_MESSAGE);

        $wallet->deductWithdrawable(1);
    }

    public function test_partial_cash_withdrawal_leaves_bonus_intact(): void
    {
        $wallet = $this->makeWallet(70, 20);
        $wallet->deductWithdrawable(50);

        $wallet->refresh();
        $this->assertEquals(20.0, (float) $wallet->balance);
        $this->assertEquals(20.0, (float) $wallet->bonus_balance);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_consume_reserved_does_not_go_negative_when_bucket_is_empty(): void
    {
        $wallet = $this->makeWallet(20, 20);

        $wallet->consumeReserved(50);

        $wallet->refresh();
        $this->assertEquals(20.0, (float) $wallet->balance);
        $this->assertEquals(0.0, (float) $wallet->reserved_balance);
        $this->assertEquals(20.0, (float) $wallet->bonus_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_reserved);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_refund_reserved_does_not_mint_cash_when_bucket_is_empty(): void
    {
        $wallet = $this->makeWallet(0, 0);

        $wallet->refundReserved(50);

        $wallet->refresh();
        $this->assertEquals(0.0, (float) $wallet->balance);
        $this->assertEquals(0.0, (float) $wallet->reserved_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_reserved);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_consume_and_refund_reserved_clamp_to_what_is_actually_reserved(): void
    {
        $wallet = $this->makeWallet(20, 20);
        $wallet->reserveForOrder(20);

        $wallet->consumeReserved(50);
        $wallet->refresh();
        $this->assertEquals(0.0, (float) $wallet->reserved_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_reserved);
        $this->assertEquals(0.0, (float) $wallet->balance);
        $this->assertSame(0.0, $wallet->withdrawableBalance());

        $wallet->refundReserved(50);
        $wallet->refresh();
        $this->assertEquals(0.0, (float) $wallet->balance);
        $this->assertEquals(0.0, (float) $wallet->reserved_balance);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }
}
