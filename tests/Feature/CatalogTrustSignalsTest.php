<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTrustSignalsTest extends TestCase
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

    private function site(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Trust Site',
            'site_url' => 'https://trust.example',
            'domain' => 'trust.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 50,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    private function orderItem(User $buyer, Site $site, string $status): OrderItem
    {
        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => $status,
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
        ]);
    }

    public function test_catalog_shows_rating_completion_and_fresh_metrics_on_closed_row(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, [
            'rating_avg' => 4.5,
            'rating_count' => 12,
            'completed_orders_count' => 8,
            'metrics_fetched_at' => now()->subDays(2),
            'screenshot_fetched_at' => now()->subDays(2),
        ]);

        for ($i = 0; $i < 8; $i++) {
            $this->orderItem($advertiser, $site, 'completed');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->orderItem($advertiser, $site, 'cancelled');
        }

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-trust-row', $html);
        $this->assertStringContainsString('4.5 (12)', $html);
        $this->assertStringContainsString('8 completed · 80%', $html);
        $this->assertStringContainsString('Updated', $html);
        $this->assertStringNotContainsString('Metrics outdated', $html);
    }

    public function test_catalog_marks_stale_metrics_and_omits_percent_for_small_samples(): void
    {
        config(['site_enrichment.max_age_days' => 90]);

        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, [
            'site_name' => 'Stale Site',
            'site_url' => 'https://stale.example',
            'domain' => 'stale.example',
            'completed_orders_count' => 1,
            'metrics_fetched_at' => now()->subDays(120),
        ]);
        $this->orderItem($advertiser, $site, 'completed');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Metrics outdated', $html);
        $this->assertStringContainsString('metrics-stale', $html);
        $this->assertStringContainsString('1 completed', $html);
        $this->assertStringNotContainsString('1 completed ·', $html);
    }

    public function test_site_compact_trust_helpers(): void
    {
        $publisher = User::factory()->create();
        $site = $this->site($publisher, [
            'rating_avg' => 4.0,
            'rating_count' => 3,
            'completed_orders_count' => 2,
            'metrics_fetched_at' => now()->subDay(),
        ]);
        $site->setAttribute('cancelled_orders_count', 1);

        $this->assertSame('4.0 (3)', $site->catalogRatingCompactLabel());
        $this->assertSame('2 completed · 67%', $site->catalogCompletionCompactLabel());
        $this->assertSame('fresh', $site->metricsFreshnessState());

        $site->forceFill([
            'rating_count' => 0,
            'completed_orders_count' => 1,
            'metrics_fetched_at' => now()->subDays(200),
        ]);
        $site->setAttribute('cancelled_orders_count', 0);

        $this->assertNull($site->catalogRatingCompactLabel());
        $this->assertSame('1 completed', $site->catalogCompletionCompactLabel());
        $this->assertSame('stale', $site->metricsFreshnessState());
    }
}
