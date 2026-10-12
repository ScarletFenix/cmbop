<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local check for the catalog Sensitive topics “Last published …” line.
 * Search the catalog for last-completed-demo.example after seeding.
 */
class CatalogLastCompletedDemoSeeder extends Seeder
{
    public function run(): void
    {
        $publisherRole = Role::query()->where('name', 'publisher')->firstOrFail();
        $advertiserRole = Role::query()->where('name', 'advertiser')->firstOrFail();

        $publisher = User::query()->where('email', 'demo.publisher@example.test')->first()
            ?? User::factory()->create([
                'name' => 'Demo Publisher',
                'email' => 'demo.publisher@example.test',
                'email_verified_at' => now(),
                'active_role_id' => $publisherRole->id,
            ]);
        $publisher->roles()->syncWithoutDetaching([$publisherRole->id]);

        $advertiser = User::query()->where('email', 'demo.advertiser@example.test')->first()
            ?? User::factory()->create([
                'name' => 'Demo Advertiser',
                'email' => 'demo.advertiser@example.test',
                'email_verified_at' => now(),
                'active_role_id' => $advertiserRole->id,
            ]);
        $advertiser->roles()->syncWithoutDetaching([$advertiserRole->id]);

        $site = Site::query()->updateOrCreate(
            ['domain' => 'last-completed-demo.example'],
            [
                'publisher_id' => $publisher->id,
                'site_name' => 'Last Completed Demo',
                'site_url' => 'https://last-completed-demo.example',
                'domain' => 'last-completed-demo.example',
                'da' => 44,
                'dr' => 51,
                'traffic' => 28000,
                'country' => 'us',
                'language' => 'en',
                'countries' => ['us'],
                'languages' => ['en'],
                'category' => 'marketing',
                'price' => 120,
                'publication_time' => 'permanent',
                'turnaround_time' => '7days',
                'link_type' => 'dofollow',
                'description' => 'Dummy listing so Sensitive topics can show the last completed-order time.',
                'sensitive_prices' => ['crypto' => 40, 'cbd' => 35],
                'verified' => true,
                'active' => 1,
            ]
        );

        $completedAt = now()->subDays(2)->subHours(4);

        $order = Order::query()->updateOrCreate(
            ['reference_code' => 'DEMO-LAST-COMPLETED'],
            [
                'user_id' => $advertiser->id,
                'order_number' => 'DEMO-LC-'.now()->format('ymd'),
                'reference_code' => 'DEMO-LAST-COMPLETED',
                'subtotal' => 120,
                'tax' => 0,
                'total_amount' => 120,
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'paid_at' => $completedAt->copy()->subDay(),
                'status' => 'completed',
                'completed_at' => $completedAt,
            ]
        );

        OrderItem::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'site_id' => $site->id,
            ],
            [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'content_link' => 'https://last-completed-demo.example/guest-post',
                'price' => 120,
                'additional_price' => 0,
                'publisher_status' => 'completed',
                'completed_at' => $completedAt,
            ]
        );

        Site::refreshCompletedOrdersCount((int) $site->id);

        $this->command?->info(
            'Catalog demo site last-completed-demo.example now has a completed order from '.$completedAt->toDateTimeString()
        );
    }
}
