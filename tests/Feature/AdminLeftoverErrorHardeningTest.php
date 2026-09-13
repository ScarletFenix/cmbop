<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\DashboardMetricsService;
use App\Services\Wallet\PayoutProfileService;
use App\Support\ProductionReadiness;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminLeftoverErrorHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
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

    /**
     * @param  TestResponse  $response
     */
    private function assertSafePage($response): void
    {
        $this->assertNotSame(500, $response->status());
        $response->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    /**
     * @param  TestResponse  $response
     */
    private function assertSafeJsonFailure($response): void
    {
        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('<html', false);

        $message = (string) ($response->json('message') ?: $response->json('error'));
        $this->assertNotSame('', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('Unknown column', $message);
    }

    public function test_sites_index_still_renders_when_sites_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($admin)->get(route('admin.sites.index'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_sites_records_html_still_renders_when_sites_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($admin)->get(route('admin.sites.records'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_user_sites_returns_json_when_sites_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($admin)->getJson(route('admin.users.sites', $publisher->id))
        );
    }

    public function test_order_show_redirects_when_order_items_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LEFT-1',
            'reference_code' => 'REF-LEFT-1',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        Schema::dropIfExists('order_items');

        $response = $this->actingAs($admin)->get(route('admin.orders.show', $order->id));

        $this->assertSafePage($response);
        $response->assertRedirect(route('admin.orders.index'));
    }

    public function test_activity_logs_still_render_when_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('activity_logs');

        $response = $this->actingAs($admin)->get(route('admin.activity-logs.index'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_catalog_activity_still_renders_when_reveal_query_throws(): void
    {
        $admin = $this->userWithRole('admin');

        Schema::dropIfExists('site_url_reveals');
        Schema::create('site_url_reveals', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
        });

        $response = $this->actingAs($admin)->get(route('admin.catalog-activity'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_bulk_requests_index_still_renders_when_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('bulk_site_requests');

        $response = $this->actingAs($admin)->get(route('admin.bulk-site-requests.index'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_dashboard_still_renders_when_production_readiness_throws(): void
    {
        $admin = $this->userWithRole('admin');

        $this->mock(ProductionReadiness::class, function ($mock) {
            $mock->shouldReceive('dashboardAlerts')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: ops boom'));
        });

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_payout_profile_returns_json_when_save_throws(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');

        $this->mock(PayoutProfileService::class, function ($mock) {
            $mock->shouldReceive('adminUpdateProfile')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: payout boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($admin)->postJson(route('admin.users.updatePayoutProfile', $publisher->id), [
                'payment_method' => 'paypal',
                'paypal_email' => 'publisher@example.com',
            ])
        );
    }

    public function test_dashboard_statistics_return_safe_json_when_metrics_throw(): void
    {
        $admin = $this->userWithRole('admin');

        $this->mock(DashboardMetricsService::class, function ($mock) {
            $mock->shouldReceive('statistics')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: metrics boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($admin)->getJson(route('admin.dashboard.statistics'))
        );
    }
}
