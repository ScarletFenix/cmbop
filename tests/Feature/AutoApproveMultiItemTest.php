<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutoApproveMultiItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
        config([
            'orders.auto_approve_hours' => 1,
            'orders.auto_approve_require_live_url_ok' => false,
            'orders.auto_approve_reminder_hours_before' => 0,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, string $domain): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'AA '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Auto-approve multi-item fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    /**
     * @return array{advertiser: User, wallet: Wallet, order: Order, first: OrderItem, second: OrderItem, pubOne: User, pubTwo: User}
     */
    private function twoItemWalletOrder(bool $secondAlsoDue): array
    {
        $advertiser = $this->userWithRole('advertiser');
        $pubOne = $this->userWithRole('publisher');
        $pubTwo = $this->userWithRole('publisher');
        $siteOne = $this->siteFor($pubOne, 'aa-one.example');
        $siteTwo = $this->siteFor($pubTwo, 'aa-two.example');

        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 160,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $pubOne->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $pubTwo->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-AA-MULTI',
            'reference_code' => 'REF-AA-MULTI',
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now(),
        ]);

        $first = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $siteOne->id,
            'site_name' => $siteOne->site_name,
            'site_url' => $siteOne->site_url,
            'content_link' => 'https://example.com/one',
            'price' => 80,
            'live_url' => 'https://aa-one.example/post',
            'live_url_submitted_at' => now()->subHours(3),
            'live_url_check_ok' => true,
            'modification_requested' => 'no',
            'content_revision_requested' => 'no',
            'auto_approve_triggered' => false,
        ]);
        $second = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $siteTwo->id,
            'site_name' => $siteTwo->site_name,
            'site_url' => $siteTwo->site_url,
            'content_link' => 'https://example.com/two',
            'price' => 80,
            'live_url' => 'https://aa-two.example/post',
            'live_url_submitted_at' => $secondAlsoDue ? now()->subHours(3) : now(),
            'live_url_check_ok' => true,
            'modification_requested' => 'no',
            'content_revision_requested' => 'no',
            'auto_approve_triggered' => false,
        ]);

        return compact('advertiser', 'wallet', 'order', 'first', 'second', 'pubOne', 'pubTwo');
    }

    public function test_first_due_item_pays_one_publisher_and_leaves_order_open(): void
    {
        $fx = $this->twoItemWalletOrder(false);

        Artisan::call('orders:auto-approve');

        $this->assertTrue((bool) $fx['first']->fresh()->auto_approve_triggered);
        $this->assertFalse((bool) $fx['second']->fresh()->auto_approve_triggered);
        $this->assertSame('review', $fx['order']->fresh()->status);
        $this->assertEqualsWithDelta(160.0, (float) $fx['wallet']->fresh()->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(
            $fx['first']->fresh()->publisherPayoutAmount(),
            (float) Wallet::query()->where('user_id', $fx['pubOne']->id)->where('role_id', Wallet::publisherRoleId())->value('balance'),
            0.01
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) Wallet::query()->where('user_id', $fx['pubTwo']->id)->where('role_id', Wallet::publisherRoleId())->value('balance'),
            0.01
        );
    }

    public function test_second_due_item_completes_order_and_consumes_reserved_once(): void
    {
        $fx = $this->twoItemWalletOrder(false);

        Artisan::call('orders:auto-approve');
        $fx['second']->update(['live_url_submitted_at' => now()->subHours(3)]);
        Artisan::call('orders:auto-approve');

        $this->assertTrue((bool) $fx['first']->fresh()->auto_approve_triggered);
        $this->assertTrue((bool) $fx['second']->fresh()->auto_approve_triggered);
        $this->assertSame('completed', $fx['order']->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $fx['wallet']->fresh()->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(
            $fx['first']->fresh()->publisherPayoutAmount(),
            (float) Wallet::query()->where('user_id', $fx['pubOne']->id)->where('role_id', Wallet::publisherRoleId())->value('balance'),
            0.01
        );
        $this->assertEqualsWithDelta(
            $fx['second']->fresh()->publisherPayoutAmount(),
            (float) Wallet::query()->where('user_id', $fx['pubTwo']->id)->where('role_id', Wallet::publisherRoleId())->value('balance'),
            0.01
        );
    }

    public function test_both_due_items_in_one_run_pay_both_publishers(): void
    {
        $fx = $this->twoItemWalletOrder(true);

        Artisan::call('orders:auto-approve');

        $this->assertSame('completed', $fx['order']->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $fx['wallet']->fresh()->reserved_balance, 0.01);
        $this->assertTrue((bool) $fx['first']->fresh()->auto_approve_triggered);
        $this->assertTrue((bool) $fx['second']->fresh()->auto_approve_triggered);
        $this->assertGreaterThan(0, (float) Wallet::query()->where('user_id', $fx['pubOne']->id)->where('role_id', Wallet::publisherRoleId())->value('balance'));
        $this->assertGreaterThan(0, (float) Wallet::query()->where('user_id', $fx['pubTwo']->id)->where('role_id', Wallet::publisherRoleId())->value('balance'));
    }
}
