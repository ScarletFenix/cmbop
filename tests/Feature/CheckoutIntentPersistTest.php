<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckoutIntentPersistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Manual Bonus Site',
            'site_url' => 'https://manual-bonus.example',
            'domain' => 'manual-bonus.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Manual bonus checkout site. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_admin_mark_paid_keeps_bonus_reserved_after_cache_flush(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 20,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'MANUAL-BONUS-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 80,
        ]);

        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $order->reference_code, 20);
        Cache::flush();

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
    }

    public function test_admin_mark_failed_refunds_bonus_after_cache_flush(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 20,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'MANUAL-BONUS-FAIL',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'bank',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 80,
        ]);

        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $order->reference_code, 20);
        Cache::flush();

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_held_bonus_reads_remembered_promo_without_consuming_it(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $intents = app(CheckoutIntentService::class);

        $this->assertSame(0.0, $intents->heldBonus(0, 'REF-HELD'));
        $this->assertSame(0.0, $intents->heldBonus($advertiser->id, ''));

        $intents->rememberBonus($advertiser->id, 'REF-HELD', 20);

        $this->assertEqualsWithDelta(20.0, $intents->heldBonus($advertiser->id, 'REF-HELD'), 0.01);
        $this->assertEqualsWithDelta(20.0, $intents->peekBonus($advertiser->id, 'REF-HELD'), 0.01);
        $this->assertEqualsWithDelta(20.0, $intents->heldBonus($advertiser->id, 'REF-HELD'), 0.01);
    }

    public function test_peek_bonus_ignores_package_snapshot_after_take(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $intents = app(CheckoutIntentService::class);
        $intents->storePackage('REF-SCRUB-PKG', [
            'user_id' => $advertiser->id,
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'lines' => [],
        ]);

        $this->assertEqualsWithDelta(20.0, $intents->takeBonus($advertiser->id, 'REF-SCRUB-PKG'), 0.01);
        $this->assertEqualsWithDelta(0.0, $intents->heldBonus($advertiser->id, 'REF-SCRUB-PKG'), 0.01);
        $this->assertEqualsWithDelta(0.0, $intents->peekBonus($advertiser->id, 'REF-SCRUB-PKG'), 0.01);
        $this->assertEqualsWithDelta(20.0, (float) ($intents->getPackage('REF-SCRUB-PKG')['bonus_applied'] ?? 0), 0.01);
    }

    public function test_store_package_after_take_does_not_resurrect_live_hold(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $intents = app(CheckoutIntentService::class);
        $intents->storePackage('REF-RESURRECT', [
            'user_id' => $advertiser->id,
            'order_total' => 80,
            'amount_due' => 60,
            'bonus_applied' => 20,
            'lines' => [],
        ]);

        $this->assertEqualsWithDelta(20.0, $intents->takeBonus($advertiser->id, 'REF-RESURRECT'), 0.01);

        $package = $intents->getPackage('REF-RESURRECT');
        $this->assertIsArray($package);
        $package['stripe_session_id'] = 'cs_after_cancel';
        $intents->storePackage('REF-RESURRECT', $package);

        $this->assertEqualsWithDelta(0.0, $intents->heldBonus($advertiser->id, 'REF-RESURRECT'), 0.01);
        $this->assertEqualsWithDelta(0.0, $intents->peekBonus($advertiser->id, 'REF-RESURRECT'), 0.01);
        $stored = $intents->getPackage('REF-RESURRECT');
        $this->assertSame('cs_after_cancel', $stored['stripe_session_id'] ?? null);
        $this->assertEqualsWithDelta(20.0, (float) ($stored['bonus_applied'] ?? 0), 0.01);
    }
}
