<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherRuntimeSyntaxFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_item_exposes_auto_approve_hours_helpers(): void
    {
        $this->assertGreaterThanOrEqual(1, OrderItem::autoApproveHours());
        $this->assertGreaterThanOrEqual(0, OrderItem::autoApproveReminderHoursBefore());
        $this->assertIsBool(OrderItem::autoApproveRequiresLiveUrlOk());
    }

    public function test_site_completed_orders_label_does_not_syntax_error(): void
    {
        $site = new Site(['completed_orders_count' => 0]);
        $this->assertSame('No completed orders yet', $site->completedOrdersLabel());

        $site->completed_orders_count = 2;
        $this->assertSame('2 completed orders', $site->completedOrdersLabel());
    }

    public function test_publisher_core_pages_render_without_fatal_errors(): void
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk();

        $this->actingAs($publisher)
            ->get(route('publisher.websites'))
            ->assertOk();

        $this->actingAs($publisher)
            ->get(route('publisher.tasks'))
            ->assertOk();

        $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk();
    }
}
