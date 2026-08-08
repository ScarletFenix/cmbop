<?php

namespace Tests\Feature;

use App\Mail\LiveUrlSubmitted;
use App\Mail\ModificationRequested;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoApproveEmailCopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeOrderFixture(): array
    {
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
            'site_name' => 'Auto Approve Mail Site',
            'site_url' => 'https://auto-approve-mail.example',
            'domain' => 'auto-approve-mail.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Auto approve email copy fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-AA-MAIL-1',
            'reference_code' => 'REF-AA-MAIL-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'status' => 'review',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://auto-approve-mail.example/article',
            'price' => 80,
            'additional_price' => 0,
            'status' => 'review',
            'live_url' => 'https://auto-approve-mail.example/post',
            'live_url_submitted_at' => now(),
        ]);

        return compact('order', 'item', 'site');
    }

    public function test_live_url_submitted_email_uses_configured_auto_approve_hours(): void
    {
        config(['orders.auto_approve_hours' => 72]);
        ['order' => $order, 'item' => $item, 'site' => $site] = $this->makeOrderFixture();

        $html = (new LiveUrlSubmitted($order, $item, $site, $item->live_url))->render();

        $this->assertStringContainsString('within 72 hours', $html);
        $this->assertStringNotContainsString('within 48 hours', $html);
    }

    public function test_modification_requested_email_uses_configured_auto_approve_hours(): void
    {
        config(['orders.auto_approve_hours' => 72]);
        ['order' => $order] = $this->makeOrderFixture();

        $html = (new ModificationRequested($order, 'Please fix the anchor text.'))->render();

        $this->assertStringContainsString('72-hour auto-approve timer', $html);
        $this->assertStringNotContainsString('48-hour auto-approve timer', $html);
    }
}
