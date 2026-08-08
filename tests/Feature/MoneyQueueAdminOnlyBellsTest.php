<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyQueueAdminOnlyBellsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketer = $this->userWithRole('marketing');
        $this->advertiser = $this->userWithRole('advertiser');
        $this->publisher = $this->userWithRole('publisher');
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

    public function test_money_queue_bells_go_to_admin_not_marketing(): void
    {
        $notifications = app(InAppNotificationService::class);

        $deposit = DepositRequest::create([
            'user_id' => $this->advertiser->id,
            'amount' => 100,
            'payment_method' => 'bank',
            'status' => 'pending',
            'reference_code' => 'DEP-MONEY-1',
        ]);
        $notifications->notifyAdminsDepositSubmitted($deposit->fresh('user'));
        $notifications->notifyAdminsDepositMarkedPaid($deposit->fresh('user'));

        $withdrawal = Withdrawal::create([
            'user_id' => $this->publisher->id,
            'amount' => 50,
            'fee' => 0,
            'net_amount' => 50,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'pending',
        ]);
        $notifications->notifyAdminsWithdrawalRequested($withdrawal, $this->publisher);

        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Money Bell Site',
            'site_url' => 'https://money-bell.example',
            'domain' => 'money-bell.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 500,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Money queue bell fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-MONEY-1',
            'reference_code' => 'REF-MONEY-1',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'wise',
        ]);
        $notifications->notifyAdminsManualPayment($this->advertiser, [$order], 'wise');

        // Site review bells should still reach marketing.
        $notifications->notifyAdminsNewSite($site, 'create');

        $adminMoney = InAppNotification::query()
            ->where('user_id', $this->admin->id)
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->whereIn('title', [
                'New deposit to review',
                'Advertiser reported a payment',
                'New withdrawal to process',
                'Manual payment to confirm',
            ])
            ->count();
        $this->assertSame(4, $adminMoney);

        $marketingMoney = InAppNotification::query()
            ->where('user_id', $this->marketer->id)
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->whereIn('title', [
                'New deposit to review',
                'Advertiser reported a payment',
                'New withdrawal to process',
                'Manual payment to confirm',
            ])
            ->count();
        $this->assertSame(0, $marketingMoney);

        $this->assertSame(
            1,
            InAppNotification::query()
                ->where('user_id', $this->marketer->id)
                ->where('related_type', Site::class)
                ->where('related_id', $site->id)
                ->count()
        );
    }
}
