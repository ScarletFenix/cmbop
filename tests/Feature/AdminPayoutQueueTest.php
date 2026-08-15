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
        $this->assertStringContainsString('renderAdminPagination(', $html);
        $this->assertStringContainsString('function escapeHtml', $html);
        $this->assertStringContainsString('Mark paid', $html);
        $this->assertStringContainsString('Open (pay these)', $html);
        $this->assertStringContainsString('const filters = filterParams();', $html);
        $this->assertStringContainsString('params.set(key, value);', $html);
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

    public function test_data_ignores_array_search_and_invalid_dates(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $open = $this->seedWithdrawal($publisher);
        $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
            'net_amount' => 10,
        ]);

        $ids = collect($this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', [
                'search' => ['injected'],
                'status' => ['completed'],
                'payment_method' => ['bank'],
                'queue' => ['history'],
                'date_from' => 'not-a-date',
                'date_to' => ['2026-01-01'],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data'))->pluck('id')->all();

        $this->assertSame([$open->id], $ids);
    }

    public function test_search_matches_wd_prefix_and_exact_id_only(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $publisher->forceFill(['name' => 'Pat Publisher', 'email' => 'pat-publisher@example.com'])->save();

        $withdrawals = [];
        for ($i = 0; $i < 12; $i++) {
            $withdrawals[] = $this->seedWithdrawal($publisher, [
                'amount' => 10 + $i,
                'fee' => 0,
                'net_amount' => 10 + $i,
            ]);
        }

        $first = $withdrawals[0];
        $eleventh = $withdrawals[10];

        $this->assertSame(1, $first->id);
        $this->assertSame(11, $eleventh->id);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', ['search' => 'WD-11']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $eleventh->id)
            ->assertJsonPath('pagination.total', 1);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', ['search' => '#wd11']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $eleventh->id)
            ->assertJsonPath('pagination.total', 1);

        $ids = collect($this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', ['search' => '1']))
            ->assertOk()
            ->json('data'))->pluck('id')->all();

        $this->assertSame([$first->id], $ids);
        $this->assertNotContains($eleventh->id, $ids);
    }

    public function test_search_by_publisher_name_still_works(): void
    {
        $admin = $this->makeUser('admin');
        $alice = $this->makeUser('publisher');
        $alice->forceFill(['name' => 'Alice Payout', 'email' => 'alice-payout@example.com'])->save();
        $bob = $this->makeUser('publisher');
        $bob->forceFill(['name' => 'Bob Wallet', 'email' => 'bob-wallet@example.com'])->save();

        $aliceRow = $this->seedWithdrawal($alice);
        $this->seedWithdrawal($bob);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', ['search' => 'Alice']))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $aliceRow->id);
    }

    public function test_csv_export_respects_queue_and_search(): void
    {
        $admin = $this->makeUser('admin');
        $alice = $this->makeUser('publisher');
        $alice->forceFill(['name' => 'Alice Export', 'email' => 'alice-export@example.com'])->save();
        $bob = $this->makeUser('publisher');
        $bob->forceFill(['name' => 'Bob Export', 'email' => 'bob-export@example.com'])->save();

        $aliceOpen = $this->seedWithdrawal($alice, [
            'payment_method' => 'bank',
            'payment_details' => [
                'bank_name' => 'Deutsche Bank',
                'account_holder' => 'Alice Export',
                'account_number' => 'DE89370400440532013000',
            ],
        ]);
        $bobOpen = $this->seedWithdrawal($bob, [
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
        ]);
        $alicePaid = $this->seedWithdrawal($alice, [
            'status' => 'completed',
            'processed_at' => now(),
            'amount' => 25,
            'fee' => 0,
            'net_amount' => 25,
        ]);

        $defaultCsv = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('WD-'.$aliceOpen->id, $defaultCsv);
        $this->assertStringContainsString('WD-'.$bobOpen->id, $defaultCsv);
        $this->assertStringNotContainsString('WD-'.$alicePaid->id, $defaultCsv);

        $historyCsv = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export', ['queue' => 'history']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('WD-'.$alicePaid->id, $historyCsv);
        $this->assertStringNotContainsString('WD-'.$aliceOpen->id, $historyCsv);
        $this->assertStringNotContainsString('WD-'.$bobOpen->id, $historyCsv);

        $searchCsv = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export', ['search' => 'Alice']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('WD-'.$aliceOpen->id, $searchCsv);
        $this->assertStringNotContainsString('WD-'.$bobOpen->id, $searchCsv);
        $this->assertStringNotContainsString('WD-'.$alicePaid->id, $searchCsv);

        $wdCsv = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export', [
                'queue' => 'history',
                'search' => 'WD-'.$alicePaid->id,
            ]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('WD-'.$alicePaid->id, $wdCsv);
        $this->assertStringNotContainsString('WD-'.$aliceOpen->id, $wdCsv);
    }

    public function test_guest_and_advertiser_cannot_load_or_export_withdrawals(): void
    {
        $advertiser = $this->makeUser('advertiser');

        $this->getJson(route('admin.withdrawals.data'))
            ->assertUnauthorized();
        $this->get(route('admin.withdrawals.export'))
            ->assertRedirect();

        $this->actingAs($advertiser)
            ->getJson(route('admin.withdrawals.data'))
            ->assertForbidden();
        $this->actingAs($advertiser)
            ->get(route('admin.withdrawals.export'))
            ->assertForbidden();
    }
}
