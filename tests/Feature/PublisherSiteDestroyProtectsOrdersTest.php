<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherSiteDestroyProtectsOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function pendingSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Pending Order Site',
            'site_url' => 'https://pending-order-site.example',
            'domain' => 'pending-order-site.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Pending listing used to assert delete cannot wipe orders.',
            'verified' => false,
            'active' => false,
        ]);
    }

    private function orderItemFor(Site $site, User $advertiser): OrderItem
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => 'https://example.com/article.docx',
        ]);
    }

    public function test_publisher_cannot_delete_a_pending_site_that_has_orders(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->pendingSite($publisher);
        $item = $this->orderItemFor($site, $advertiser);

        $this->actingAs($publisher)
            ->from(route('publisher.sites.index'))
            ->delete(route('publisher.sites.destroy', $site->id))
            ->assertRedirect(route('publisher.sites.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'site_id' => $site->id]);
    }

    public function test_publisher_can_delete_a_pending_site_without_orders(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->pendingSite($publisher);

        $this->actingAs($publisher)
            ->from(route('publisher.sites.index'))
            ->delete(route('publisher.sites.destroy', $site->id))
            ->assertRedirect(route('publisher.sites.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }
}
