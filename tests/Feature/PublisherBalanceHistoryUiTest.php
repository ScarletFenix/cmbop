<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherBalanceHistoryUiTest extends TestCase
{
    use RefreshDatabase;

    private function publisherWithWallets(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'marketing']);
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
            'balance' => 7.64,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advertiser->id,
            'balance' => 20,
            'reserved_balance' => 0,
            'bonus_balance' => 20,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        return $user;
    }

    public function test_balance_page_does_not_offer_role_transfers(): void
    {
        $user = $this->publisherWithWallets();

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Publisher earnings', $html);
        $this->assertStringContainsString('Withdrawable', $html);
        $this->assertStringContainsString('€7.64', $html);
        $this->assertStringContainsString('Internal wallet transfers are no longer offered', $html);
        $this->assertStringNotContainsString('Transfer to Advertiser Wallet', $html);
        $this->assertStringNotContainsString('0% Transfer Fee', $html);
        $this->assertStringNotContainsString('id="transferBtn"', $html);
        $this->assertStringNotContainsString('id="amount"', $html);
        $this->assertStringNotContainsString('function renderTransferHistory', $html);
        $this->assertStringNotContainsString('Transfer History', $html);
        $this->assertStringNotContainsString('Ready to transfer or withdraw', $html);
        $this->assertStringNotContainsString('/publisher/balance/transfer', $html);
    }

    public function test_publisher_balance_uses_role_name_ids_not_hardcoded_one_and_two(): void
    {
        $user = $this->publisherWithWallets();
        $publisherId = (int) Wallet::publisherRoleId();
        $advertiserId = (int) Wallet::advertiserRoleId();

        $this->assertGreaterThan(2, $publisherId);
        $this->assertGreaterThan(2, $advertiserId);

        $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->assertSee('€7.64', false);
    }

    public function test_publisher_role_transfer_endpoint_stays_gone(): void
    {
        $user = $this->publisherWithWallets();

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 5])
            ->assertStatus(410)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'transfers_disabled');

        $this->assertSame(
            7.64,
            (float) Wallet::where('user_id', $user->id)
                ->where('role_id', Wallet::publisherRoleId())
                ->value('balance')
        );
    }

    public function test_favicon_partial_points_at_existing_public_assets(): void
    {
        $partial = file_get_contents(resource_path('views/components/favicon.blade.php'));
        $this->assertStringContainsString('assets/brand/web/favicon.svg', $partial);
        $this->assertStringContainsString('assets/img/favicon-32.png', $partial);
        $this->assertStringContainsString('assets/img/apple-touch-icon.png', $partial);
        $this->assertFileExists(public_path('assets/brand/web/favicon.svg'));
        $this->assertFileExists(public_path('assets/img/favicon-32.png'));
        $this->assertFileExists(public_path('assets/img/apple-touch-icon.png'));
        $this->assertFileExists(public_path('favicon.svg'));
        $this->assertFileExists(public_path('favicon.ico'));
        $this->assertFileExists(public_path('apple-touch-icon.png'));
    }
}
