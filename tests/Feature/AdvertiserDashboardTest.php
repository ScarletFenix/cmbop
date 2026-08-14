<?php

namespace Tests\Feature;

use App\Models\AdvertiserSpendBudget;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Advertiser\AdvertiserDashboardService;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvertiserDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function siteFor(User $publisher, array $attrs = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Dash Site',
            'site_url' => 'https://dash-site.example',
            'domain' => 'dash-site.example',
            'da' => 40,
            'dr' => 55,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'en',
            'category' => 'News',
            'price' => 100,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ], $attrs));
    }

    private function makeOrder(User $advertiser, array $attrs = [], ?Site $site = null): Order
    {
        $publisher = User::factory()->create();
        $site = $site ?: $this->siteFor($publisher);

        return DB::transaction(function () use ($advertiser, $site, $attrs) {
            $order = Order::create(array_merge([
                'user_id' => $advertiser->id,
                'order_number' => 'ORD-'.uniqid(),
                'reference_code' => 'REF-'.uniqid(),
                'subtotal' => 100,
                'tax' => 0,
                'total_amount' => 100,
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'status' => 'processing',
                'paid_at' => now(),
            ], $attrs));

            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => (float) ($attrs['total_amount'] ?? 100),
                'additional_price' => 0,
                'content_link' => 'https://example.com/a.docx',
                'live_url' => $attrs['live_url'] ?? null,
            ]);

            return $order->fresh(['items']);
        });
    }

    public function test_unpaid_pending_excluded_from_in_progress(): void
    {
        $user = $this->advertiser();
        $this->makeOrder($user, ['status' => 'pending', 'payment_status' => 'pending', 'paid_at' => null]);
        $this->makeOrder($user, ['status' => 'processing', 'payment_status' => 'paid']);

        $stats = app(AdvertiserDashboardService::class)->orderStats($user->id);
        $this->assertSame(1, $stats['in_progress']);
        $this->assertSame(1, $stats['awaiting_payment']);
    }

    public function test_upcoming_scheduled_orders_are_not_in_progress(): void
    {
        $user = $this->advertiser();
        $this->makeOrder($user, [
            'status' => 'pending',
            'payment_status' => 'paid',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(4),
            'schedule_timezone' => 'Europe/Berlin',
        ]);
        $this->makeOrder($user, [
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);

        $stats = app(AdvertiserDashboardService::class)->orderStats($user->id);
        $this->assertSame(1, $stats['in_progress']);
        $this->assertSame(2, $stats['total']);

        $scheduled = Order::query()
            ->where('user_id', $user->id)
            ->where('publication_mode', 'scheduled')
            ->with('items')
            ->first();
        $meta = AdvertiserOrderStatus::meta($scheduled);
        $this->assertSame('scheduled', $meta['stage']);
        $this->assertStringContainsString('Scheduled', $meta['label']);

        $steps = AdvertiserOrderStatus::timelineSteps($scheduled);
        $this->assertSame('Scheduled', $steps[1]['label']);
        $this->assertTrue($steps[1]['current']);
        $this->assertFalse($steps[1]['done']);

        $this->actingAs($user)
            ->getJson(route('advertiser.orders.statistics'))
            ->assertOk()
            ->assertJsonPath('data.in_progress', 1);

        $inProgress = $this->actingAs($user)
            ->getJson(route('advertiser.orders.list', ['status' => 'in_progress']))
            ->assertOk()
            ->json('orders');
        $this->assertCount(1, $inProgress);
        $this->assertSame('processing', $inProgress[0]['status']);

        $awaitingPublisher = $this->actingAs($user)
            ->getJson(route('advertiser.orders.list', ['status' => 'awaiting_publisher']))
            ->assertOk()
            ->json('orders');
        $this->assertCount(0, $awaitingPublisher);

        $all = $this->actingAs($user)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->json('orders');
        $scheduledRow = collect($all)->firstWhere('publication_mode', 'scheduled');
        $this->assertNotNull($scheduledRow);
        $this->assertSame('status-processing', $scheduledRow['status_cls']);
        $this->assertStringContainsString('Scheduled', $scheduledRow['status_label']);
    }

    public function test_needs_action_and_recommended_site_link(): void
    {
        $user = $this->advertiser();
        $publisher = User::factory()->create();
        $site = $this->siteFor($publisher, ['dr' => 90, 'traffic' => 999999]);
        $this->makeOrder($user, [
            'status' => 'review',
            'payment_status' => 'paid',
            'live_url' => 'https://live.example/x',
        ], $site);

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('your attention', false)
            ->assertSee('Orders need attention', false)
            ->assertSee(route('advertiser.catalog', ['site' => $site->id]), false)
            ->assertDontSee('sort=dr_desc', false);
    }

    public function test_recent_order_deep_link_and_spend_strip(): void
    {
        $user = $this->advertiser();
        $order = $this->makeOrder($user, [
            'status' => 'processing',
            'payment_status' => 'paid',
            'total_amount' => 77,
        ]);

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('focus=order', false)
            ->assertSee('order='.$order->id, false)
            ->assertSee('Net spend', false)
            ->assertSee('In progress', false)
            ->assertDontSee('In progress €', false)
            ->assertSee('Spendable', false)
            ->assertSee('dash-spend-chart-wrap', false)
            ->assertSee('Solid = completed', false)
            ->assertSee('dashSpendChartFallback', false)
            ->assertSee('Spent (completed)', false)
            ->assertSee('dash-recent-col', false)
            ->assertSee('dash-page-end', false)
            ->assertSee('Recent orders', false);
    }

    public function test_low_balance_warning_and_telegram_config(): void
    {
        $user = $this->advertiser();
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        AdvertiserSpendBudget::create([
            'user_id' => $user->id,
            'monthly_limit' => 500,
            'warn_at_percent' => 80,
            'low_balance_threshold' => 25,
            'notify_email' => false,
            'notify_bell' => false,
        ]);
        $this->makeOrder($user, ['status' => 'completed', 'payment_status' => 'paid']);

        config(['services.support.telegram_url' => 'https://t.me/test-support-bot']);

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Top up — low balance', false)
            ->assertSee('https://t.me/test-support-bot', false);
    }
}
