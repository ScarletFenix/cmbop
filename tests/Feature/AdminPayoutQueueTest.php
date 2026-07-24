<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPayoutQueueTest extends TestCase
{
    use RefreshDatabase;

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

    private function seedWithdrawal(User $publisher, array $overrides = []): Withdrawal
    {
        return Withdrawal::create(array_merge([
            'user_id' => $publisher->id,
            'amount' => 100,
            'fee' => 5,
            'net_amount' => 95,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'pending',
        ], $overrides));
    }

    public function test_finance_overview_page_loads_for_admin(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('Finance overview')
            ->assertSee('Due to pay now')
            ->assertSee('In publisher wallets')
            ->assertSee('Total publisher liability')
            ->assertSee('Order platform fees')
            ->assertSee('Cash into your accounts')
            ->assertSee('Money in')
            ->assertSee('Money out');
    }

    public function test_payout_queue_page_defaults_to_pay_these_people_copy(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.withdrawals'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Payout queue', $html);
        $this->assertStringContainsString('function renderPagination', $html);
        $this->assertStringContainsString('function escapeHtml', $html);
        $this->assertStringContainsString('Mark paid', $html);
        $this->assertStringContainsString('Open (pay these)', $html);
    }

    public function test_data_endpoint_defaults_to_open_queue_oldest_first(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');

        $older = $this->seedWithdrawal($publisher, [
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);
        $newer = $this->seedWithdrawal($publisher, [
            'amount' => 50,
            'fee' => 0,
            'net_amount' => 50,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'net_amount' => 10,
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$older->id, $newer->id], $ids);
        $this->assertArrayHasKey('destination_snippet', $response->json('data.0'));
        $this->assertArrayHasKey('destination_copy_text', $response->json('data.0'));
    }

    public function test_statistics_include_amounts_and_by_method(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');

        $this->seedWithdrawal($publisher, ['payment_method' => 'paypal', 'net_amount' => 95]);
        $this->seedWithdrawal($publisher, [
            'payment_method' => 'bank',
            'payment_details' => [
                'bank_name' => 'Test Bank',
                'account_holder' => 'Pub',
                'account_number' => 'DE89370400440532013000',
            ],
            'net_amount' => 200,
            'status' => 'processing',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.processing', 1)
            ->assertJsonPath('data.total_to_pay', 295)
            ->assertJsonStructure(['data' => ['by_method', 'completed_this_week', 'pending_amount']]);
    }

    public function test_mark_paid_sets_processed_at_and_saves_notes(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher, ['status' => 'processing']);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.paid', $withdrawal->id), [
                'notes' => 'Wise #9988',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $withdrawal->refresh();
        $this->assertSame('completed', $withdrawal->status);
        $this->assertSame('Wise #9988', $withdrawal->admin_notes);
        $this->assertNotNull($withdrawal->processed_at);
    }

    public function test_reject_refunds_wallet_and_saves_notes(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $publisherRoleId = Role::firstOrCreate(['name' => 'publisher'])->id;

        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisherRoleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $withdrawal = $this->seedWithdrawal($publisher, [
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.reject', $withdrawal->id), [
                'notes' => 'Invalid IBAN',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('cancelled', $withdrawal->fresh()->status);
        $this->assertSame('Invalid IBAN', $withdrawal->fresh()->admin_notes);
        $this->assertSame(40.0, (float) Wallet::where('user_id', $publisher->id)->first()->balance);
    }

    public function test_batch_mark_paid_creates_payout_run_id(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $a = $this->seedWithdrawal($publisher);
        $b = $this->seedWithdrawal($publisher, ['amount' => 20, 'fee' => 0, 'net_amount' => 20]);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.batch'), [
                'ids' => [$a->id, $b->id],
                'action' => 'completed',
                'notes' => 'Friday payday',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('succeeded', 2)
            ->assertJsonStructure(['payout_run_id']);

        $this->assertSame('completed', $a->fresh()->status);
        $this->assertSame('completed', $b->fresh()->status);
        $this->assertSame('Friday payday', $a->fresh()->admin_notes);
    }

    public function test_csv_export_includes_sepa_columns(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->seedWithdrawal($publisher, [
            'payment_method' => 'bank',
            'payment_details' => [
                'bank_name' => 'Deutsche Bank',
                'account_holder' => 'Jane Pub',
                'account_number' => 'DE89370400440532013000',
                'swift_code' => 'DEUTDEFF',
            ],
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export'))
            ->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('reference', $csv);
        $this->assertStringContainsString('iban_account', $csv);
        $this->assertStringContainsString('DE89370400440532013000', $csv);
        $this->assertStringContainsString('WD-', $csv);
    }

    public function test_publisher_sees_requested_paid_labels(): void
    {
        $publisher = $this->makeUser('publisher');
        Role::firstOrCreate(['name' => 'publisher']);

        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisher->active_role_id,
            'balance' => 50,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->seedWithdrawal($publisher, ['status' => 'pending']);
        $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
            'amount' => 10,
            'fee' => 0,
            'net_amount' => 10,
        ]);

        $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->assertSee('Requested')
            ->assertSee('Paid');
    }

    public function test_update_status_still_works_for_legacy_clients(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher, ['status' => 'pending']);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.update-status', $withdrawal->id), [
                'status' => 'processing',
                'notes' => 'Working on it',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('processing', $withdrawal->fresh()->status);
        $this->assertSame('Working on it', $withdrawal->fresh()->admin_notes);
    }
}
