<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletRoleMoveException;
use App\Services\Wallet\WalletRoleMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletRoleMoveServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, float>  $overrides
     */
    private function dualRoleUser(array $overrides = []): User
    {
        $advertiser = Role::firstOrCreate(['name' => 'advertiser']);
        $publisher = Role::firstOrCreate(['name' => 'publisher']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisher->id,
        ]);
        $user->roles()->attach([$publisher->id, $advertiser->id]);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisher->id,
            'balance' => $overrides['publisher_balance'] ?? 25,
            'reserved_balance' => 0,
            'bonus_balance' => $overrides['publisher_bonus'] ?? 0,
            'bonus_reserved' => 0,
            'debt_balance' => $overrides['publisher_debt'] ?? 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advertiser->id,
            'balance' => $overrides['advertiser_balance'] ?? 20,
            'reserved_balance' => 0,
            'bonus_balance' => $overrides['advertiser_bonus'] ?? 20,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        return $user;
    }

    public function test_service_moves_withdrawable_without_touching_bonus_or_transfer_in(): void
    {
        $user = $this->dualRoleUser();

        $result = app(WalletRoleMoveService::class)->publisherToAdvertiser($user, 10.1);

        $this->assertSame(10.1, $result['amount']);
        $this->assertSame(0.0, $result['fee']);
        $this->assertSame(10.1, $result['net_amount']);
        $this->assertSame(14.9, $result['publisher']['withdrawable']);
        $this->assertSame(30.1, $result['advertiser']['spendable']);
        $this->assertSame(20.0, $result['advertiser']['bonus']);
        $this->assertSame(0, WalletTransaction::where('type', WalletTransaction::TYPE_TRANSFER_IN)->count());
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_ROLE_MOVE_OUT)->count());
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_ROLE_MOVE_IN)->count());
    }

    public function test_service_throws_wallet_debt_without_mutating_balances(): void
    {
        $user = $this->dualRoleUser(['publisher_debt' => 3]);

        try {
            app(WalletRoleMoveService::class)->publisherToAdvertiser($user, 5);
            $this->fail('Expected WalletRoleMoveException');
        } catch (WalletRoleMoveException $e) {
            $this->assertSame('wallet_debt', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }

        $this->assertSame(
            25.0,
            (float) Wallet::where('user_id', $user->id)
                ->where('role_id', Wallet::publisherRoleId())
                ->value('balance')
        );
    }

    public function test_service_rejects_publisher_only_users(): void
    {
        $publisher = Role::firstOrCreate(['name' => 'publisher']);
        Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisher->id,
        ]);
        $user->roles()->attach($publisher->id);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisher->id,
            'balance' => 25,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->expectException(WalletRoleMoveException::class);
        $this->expectExceptionMessage('You need an advertiser account to move earnings for spending.');

        app(WalletRoleMoveService::class)->publisherToAdvertiser($user, 5);
    }
}
