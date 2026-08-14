<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $role = Role::create(['name' => 'admin']);
        $user = User::factory()->create([
            'active_role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_admin_dashboard_loads(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Admin Dashboard')
            ->assertSee('Needs Attention');
    }

    public function test_admin_queue_counts_endpoint(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'pending_deposits' => 0,
                'pending_withdrawals' => 0,
                'unverified_sites' => 0,
                'pending_payments' => 0,
                'pending_claims' => 0,
                'pending_community' => 0,
                'open_disputes' => 0,
                'needs_attention' => 0,
            ]);
    }

    public function test_admin_statistics_endpoint(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_users', 1)
            ->assertJsonPath('data.admins', 1)
            ->assertJsonPath('data.advertisers', 0)
            ->assertJsonPath('data.pending_deposits', 0)
            ->assertJsonPath('data.needs_attention', 0)
            ->assertJsonPath('data.live_sites', 0);
    }

    public function test_admin_trends_distributions_and_action_queue_endpoints(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.trends'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(30, 'labels')
            ->assertJsonCount(30, 'revenue');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.distributions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['orders' => ['labels', 'values'], 'roles' => ['labels', 'values']]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'deposits' => [],
                'withdrawals' => [],
                'sites' => [],
            ]);
    }

    public function test_non_admin_cannot_access_ops_dashboard(): void
    {
        $role = Role::create(['name' => 'advertiser']);
        $user = User::factory()->create([
            'active_role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertStatus(403);
    }

    public function test_processing_withdrawals_appear_in_the_action_queue(): void
    {
        $admin = $this->makeAdmin();
        Withdrawal::create([
            'user_id' => $admin->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'processing',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('withdrawals.0.status', 'processing')
            ->assertJsonPath('withdrawals.0.amount', 40);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_withdrawals', 1)
            ->assertJsonPath('needs_attention', 1);
    }

    public function test_needs_attention_includes_unpaid_orders_and_community(): void
    {
        $admin = $this->makeAdmin();

        Order::create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-ATTN-1',
            'reference_code' => 'REF-ATTN-1',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'bank',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        ProblemReport::create([
            'name' => 'Reporter',
            'email' => 'ops@example.com',
            'subject' => 'Broken checkout',
            'message' => 'Cannot pay',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_payments', 1)
            ->assertJsonPath('pending_claims', 0)
            ->assertJsonPath('pending_community', 1)
            ->assertJsonPath('needs_attention', 2);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.needs_attention', 2)
            ->assertJsonPath('data.pending_payments', 1)
            ->assertJsonPath('data.pending_community', 1);
    }

    public function test_seven_day_gmv_uses_paid_at_not_created_at(): void
    {
        $admin = $this->makeAdmin();

        $order = Order::create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-GMV-1',
            'reference_code' => 'REF-GMV-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now()->subDay(),
        ]);
        $order->created_at = now()->subDays(10);
        $order->save();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.revenue_7d', 80);
    }
}
