<?php

namespace Tests\Feature;

use App\Mail\SiteOwnerOrderNotification;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteOwnerOrderEmailCtaTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_owner_new_order_email_links_to_publisher_tasks(): void
    {
        $this->seed(RolesTableSeeder::class);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();

        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Owner Order Site',
            'site_url' => 'https://owner-order.example',
            'domain' => 'owner-order.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 2000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Site owner order email CTA fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-OWNER-1',
            'reference_code' => 'REF-OWNER-1',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'status' => 'pending',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'paid_at' => now(),
        ]);

        $html = (new SiteOwnerOrderNotification($site, [$order]))->render();

        $this->assertStringContainsString('/publisher/tasks', $html);
        $this->assertStringContainsString('focus=order', $html);
        $this->assertStringContainsString('order='.$order->id, $html);
        $this->assertStringContainsString('View Your Tasks', $html);
        $this->assertStringNotContainsString('/publisher/sites', $html);
        $this->assertStringNotContainsString('View Your Sites', $html);
    }
}
