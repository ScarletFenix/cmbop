<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminLedgerPaymentsInvoicesCrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function admin(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function advertiser(): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_ledger_survives_leftover_unparseable_created_at(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 40,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'currency' => 'EUR',
        ]);

        $tx = WalletTransaction::create([
            'user_id' => $advertiser->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'direction' => 'credit',
            'amount' => 40,
            'bonus_amount' => 0,
            'balance_after' => 40,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'Leftover date deposit',
            'reference' => 'DEP-LEFTOVER-DATE',
        ]);
        DB::table('wallet_transactions')->where('id', $tx->id)->update([
            'created_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->assertSee('DEP-LEFTOVER-DATE');
    }

    public function test_ledger_survives_missing_wallets_table(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        WalletTransaction::create([
            'user_id' => $advertiser->id,
            'wallet_id' => null,
            'type' => WalletTransaction::TYPE_ADJUSTMENT,
            'direction' => 'credit',
            'amount' => 5,
            'bonus_amount' => 0,
            'balance_after' => 5,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'No wallets table',
            'reference' => 'ADJ-NO-WALLETS',
        ]);

        try {
            Schema::dropIfExists('wallets');
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop wallets on this driver');
        }
        if (Schema::hasTable('wallets')) {
            $this->markTestSkipped('wallets is still present after drop');
        }

        try {
            $this->actingAs($admin)
                ->get(route('admin.finance.ledger'))
                ->assertOk()
                ->assertDontSee('Something went wrong')
                ->assertSee('ADJ-NO-WALLETS');
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

    public function test_payments_data_survives_missing_invoices_table(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'PAY-NO-INV-1',
            'reference_code' => 'REF-NO-INV-1',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->dropBillingTables();
        $this->assertFalse(Schema::hasTable('invoices'));

        try {
            $this->actingAs($admin)
                ->getJson(route('admin.payments.data', ['search' => 'PAY-NO-INV-1']))
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.0.order_number', 'PAY-NO-INV-1');

            $this->actingAs($admin)
                ->get(route('admin.payments.export', ['search' => 'PAY-NO-INV-1']))
                ->assertOk();
        } finally {
            $this->restoreBillingTables();
        }
    }

    public function test_payments_paid_at_filter_survives_missing_column(): void
    {
        if (! Schema::hasColumn('orders', 'paid_at')) {
            $this->markTestSkipped('orders.paid_at is already absent');
        }

        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('paid_at');
            });
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop orders.paid_at on this driver');
        }

        if (Schema::hasColumn('orders', 'paid_at')) {
            $this->markTestSkipped('orders.paid_at is still present after drop');
        }

        try {
            $admin = $this->admin();
            $advertiser = $this->advertiser();
            Order::create([
                'user_id' => $advertiser->id,
                'order_number' => 'PAY-NO-PAID-AT',
                'reference_code' => 'REF-NO-PAID-AT',
                'subtotal' => 40,
                'tax' => 0,
                'total_amount' => 40,
                'payment_method' => 'bank',
                'payment_status' => 'paid',
                'status' => 'pending',
            ]);

            $this->actingAs($admin)
                ->getJson(route('admin.payments.data', [
                    'date_field' => 'paid_at',
                    'date_from' => '2026-01-01',
                    'date_to' => '2026-12-31',
                ]))
                ->assertOk()
                ->assertJsonPath('success', true);
        } finally {
            if (! Schema::hasColumn('orders', 'paid_at')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->timestamp('paid_at')->nullable();
                });
            }
        }
    }

    public function test_invoices_index_survives_missing_tables_and_array_filters(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        Invoice::create([
            'invoice_number' => 'INV-ARRAY-1',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $advertiser->id,
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'subtotal' => 10,
            'total_amount' => 10,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Test', 'line_total' => 10]],
            'pdf_disk' => 'not-a-real-disk',
            'pdf_path' => 'invoices/ghost.pdf',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index', [
                'search' => ['injected'],
                'status' => ['paid'],
                'type' => ['tax_invoice'],
            ]))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->assertSee('INV-ARRAY-1');

        Schema::dropIfExists('billing_events');
        $this->assertFalse(Schema::hasTable('billing_events'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.invoices.index'))
                ->assertOk()
                ->assertDontSee('Something went wrong')
                ->assertSee('INV-ARRAY-1');

            $invoice = Invoice::query()->where('invoice_number', 'INV-ARRAY-1')->firstOrFail();
            $this->actingAs($admin)
                ->get(route('admin.invoices.show', $invoice))
                ->assertOk()
                ->assertDontSee('Something went wrong');
        } finally {
            $this->restoreBillingEventsTable();
        }
    }

    public function test_invoices_index_survives_missing_invoices_table(): void
    {
        $admin = $this->admin();

        $this->dropBillingTables();
        $this->assertFalse(Schema::hasTable('invoices'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.invoices.index'))
                ->assertOk()
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->from(route('admin.invoices.index'))
                ->post(route('admin.invoices.generate'), ['order_id' => 1])
                ->assertRedirect(route('admin.invoices.index'))
                ->assertSessionHas('error');
        } finally {
            $this->restoreBillingTables();
        }
    }

    private function dropBillingTables(): void
    {
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
    }

    private function restoreBillingTables(): void
    {
        $this->dropBillingTables();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_17_100000_create_billing_invoices_tables.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_08_02_110000_add_series_to_invoice_sequences_table.php',
            '--force' => true,
        ]);
    }

    private function restoreBillingEventsTable(): void
    {
        if (Schema::hasTable('billing_events')) {
            return;
        }

        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 80)->index();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
}
