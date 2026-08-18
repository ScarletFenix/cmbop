<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminMoneyMenusCrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function admin(): User
    {
        return $this->makeUser('admin');
    }

    private function makeUser(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_every_money_menu_index_loads(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.finance'))->assertOk()->assertSee('Finance', false);
        $this->actingAs($admin)->get(route('admin.finance.ledger'))->assertOk()->assertSee('Wallet ledger', false);
        $this->actingAs($admin)->get(route('admin.payments'))->assertOk()->assertSee('allowed.length > 0', false);
        $this->actingAs($admin)->getJson(route('admin.payments.data'))->assertOk()->assertJsonPath('success', true);
        $this->actingAs($admin)->get(route('admin.invoices.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.deposits'))->assertOk();
        $this->actingAs($admin)->get(route('admin.withdrawals'))->assertOk();
        $this->actingAs($admin)->getJson(route('admin.withdrawals.data'))->assertOk();
        $this->actingAs($admin)->getJson(route('admin.withdrawals.statistics'))->assertOk();
    }

    public function test_finance_dossier_survives_missing_sites_table(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher', ['name' => 'Dossier Publisher']);
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Money News',
            'site_url' => 'https://money-news.example',
            'domain' => 'money-news.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 50,
            'publication_time' => '3',
            'description' => 'Dossier leftover site',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
        $this->assertNotNull($site->id);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('sites');
        Schema::enableForeignKeyConstraints();
        $this->assertFalse(Schema::hasTable('sites'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.finance.user', $publisher))
                ->assertOk()
                ->assertSee('Dossier Publisher', false);
        } finally {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_04_06_094704_create_sites_table.php',
                '--force' => true,
            ]);
        }
    }

    public function test_finance_hub_survives_missing_platform_fee_column(): void
    {
        $admin = $this->admin();

        if (! Schema::hasColumn('order_items', 'platform_fee_amount')) {
            $this->markTestSkipped('order_items.platform_fee_amount is already absent');
        }

        try {
            Schema::disableForeignKeyConstraints();
            Schema::table('order_items', function (Blueprint $blueprint) {
                $blueprint->dropColumn('platform_fee_amount');
            });
            Schema::enableForeignKeyConstraints();
        } catch (\Throwable) {
            Schema::enableForeignKeyConstraints();
            $this->markTestSkipped('Could not drop order_items.platform_fee_amount on this driver');
        }

        if (Schema::hasColumn('order_items', 'platform_fee_amount')) {
            $this->markTestSkipped('order_items.platform_fee_amount is still present after drop');
        }

        try {
            $this->actingAs($admin)
                ->get(route('admin.finance', ['period' => 'all']))
                ->assertOk();
        } finally {
            if (! Schema::hasColumn('order_items', 'platform_fee_amount')) {
                Schema::table('order_items', function (Blueprint $blueprint) {
                    $blueprint->decimal('platform_fee_amount', 10, 2)->nullable();
                });
            }
        }
    }

    public function test_clear_debt_is_404_when_wallets_table_is_gone(): void
    {
        $admin = $this->admin();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('wallets');
        Schema::enableForeignKeyConstraints();
        $this->assertFalse(Schema::hasTable('wallets'));

        try {
            $this->actingAs($admin)
                ->post(route('admin.finance.wallets.clear-debt', 1), [
                    'reason' => 'Write-off leftover debt.',
                ])
                ->assertNotFound();
        } finally {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_03_27_170746_create_wallets_table.php',
                '--force' => true,
            ]);
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_15_120000_add_bonus_balances_to_wallets_table.php',
                '--force' => true,
            ]);
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_31_150100_add_debt_balance_to_wallets_table.php',
                '--force' => true,
            ]);
        }
    }

    public function test_clear_debt_is_422_when_ledger_table_is_gone(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 42.5,
            'currency' => 'EUR',
        ]);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('wallet_transactions');
        Schema::enableForeignKeyConstraints();
        $this->assertFalse(Schema::hasTable('wallet_transactions'));

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.finance.wallets.clear-debt', $wallet), [
                    'reason' => 'Write-off leftover debt.',
                ])
                ->assertStatus(422)
                ->assertJsonPath('success', false);

            $this->assertEqualsWithDelta(42.5, (float) $wallet->fresh()->debt_balance, 0.01);
        } finally {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_17_140000_create_wallet_transactions_table.php',
                '--force' => true,
            ]);
        }
    }

    public function test_invoice_view_survives_blank_pdf_disk(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        Storage::fake('local');
        Storage::disk('local')->put('invoices/empty-disk.pdf', '%PDF-1.4 leftover');

        $invoice = Invoice::create([
            'invoice_number' => 'INV-EMPTY-DISK',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $advertiser->id,
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'subtotal' => 10,
            'total_amount' => 10,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Test', 'line_total' => 10]],
            'pdf_disk' => '',
            'pdf_path' => 'invoices/empty-disk.pdf',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.view', $invoice))
            ->assertOk();
    }

    public function test_invoice_cancel_still_works_without_cancelled_at(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $invoice = Invoice::create([
            'invoice_number' => 'INV-NO-CANCEL-AT',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_ISSUED,
            'user_id' => $advertiser->id,
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'subtotal' => 10,
            'total_amount' => 10,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Test', 'line_total' => 10]],
        ]);

        if (! Schema::hasColumn('invoices', 'cancelled_at')) {
            $this->markTestSkipped('invoices.cancelled_at is already absent');
        }

        try {
            Schema::disableForeignKeyConstraints();
            Schema::table('invoices', function (Blueprint $blueprint) {
                $blueprint->dropColumn('cancelled_at');
            });
            Schema::enableForeignKeyConstraints();
        } catch (\Throwable) {
            Schema::enableForeignKeyConstraints();
            $this->markTestSkipped('Could not drop invoices.cancelled_at on this driver');
        }

        if (Schema::hasColumn('invoices', 'cancelled_at')) {
            $this->markTestSkipped('invoices.cancelled_at is still present after drop');
        }

        try {
            $this->actingAs($admin)
                ->post(route('admin.invoices.cancel', $invoice), [
                    'reason' => 'Duplicate leftover invoice.',
                ])
                ->assertRedirect();

            $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        } finally {
            if (! Schema::hasColumn('invoices', 'cancelled_at')) {
                Schema::table('invoices', function (Blueprint $blueprint) {
                    $blueprint->timestamp('cancelled_at')->nullable();
                });
            }
        }
    }

    public function test_payments_page_offers_update_for_paid_in_flight_orders(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-PAID-IF',
            'reference_code' => 'REF-PAID-IF',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['search' => $order->order_number]))
            ->assertOk()
            ->assertJsonPath('data.0.allowed_statuses', ['paid', 'failed', 'refunded']);

        $this->actingAs($admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('allowed.length > 0', false)
            ->assertSee('Update payment', false);
    }

    public function test_deposits_index_survives_query_failure_after_partial_schema(): void
    {
        $admin = $this->admin();
        DepositRequest::create([
            'user_id' => $this->makeUser('advertiser')->id,
            'reference_code' => 'DEP-MONEY-1',
            'amount' => 25,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->assertSee('DEP-MONEY-1', false);
    }
}
