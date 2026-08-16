<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogVisibleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

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

    private function site(User $publisher, array $attrs = []): Site
    {
        $slug = $attrs['domain'] ?? ('vis-'.uniqid());

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Visible '.$slug,
            'site_url' => 'https://'.$slug,
            'domain' => $slug,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 150,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Catalog visibility fixture.',
            'verified' => true,
            'active' => true,
        ], $attrs));
    }

    public function test_catalog_lists_only_active_verified_not_archived_sites(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $live = $this->site($publisher, [
            'site_name' => 'Live Catalog Site',
            'domain' => 'live-catalog.example',
            'site_url' => 'https://live-catalog.example',
        ]);
        $this->site($publisher, [
            'site_name' => 'Unverified Catalog Site',
            'domain' => 'unverified-catalog.example',
            'site_url' => 'https://unverified-catalog.example',
            'verified' => false,
        ]);
        $this->site($publisher, [
            'site_name' => 'Inactive Catalog Site',
            'domain' => 'inactive-catalog.example',
            'site_url' => 'https://inactive-catalog.example',
            'active' => false,
        ]);
        $this->site($publisher, [
            'site_name' => 'Archived Catalog Site',
            'domain' => 'archived-catalog.example',
            'site_url' => 'https://archived-catalog.example',
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->assertTrue($live->isCatalogVisible());
        $this->assertSame(1, Site::query()->catalogVisible()->count());

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Live Catalog Site', $html);
        $this->assertStringNotContainsString('Unverified Catalog Site', $html);
        $this->assertStringNotContainsString('Inactive Catalog Site', $html);
        $this->assertStringNotContainsString('Archived Catalog Site', $html);
    }

    public function test_unparseable_archived_at_does_not_hide_a_live_listing(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $leftover = $this->site($publisher, [
            'site_name' => 'Garbage Archived Site',
            'domain' => 'garbage-archived.example',
            'site_url' => 'https://garbage-archived.example',
        ]);
        DB::table('sites')->where('id', $leftover->id)->update([
            'archived_at' => 'not-a-date',
        ]);

        $fresh = $leftover->fresh();
        $this->assertFalse($fresh->isArchived());
        $this->assertTrue($fresh->isCatalogVisible());
        $this->assertTrue(Site::query()->catalogVisible()->whereKey($leftover->id)->exists());
        $this->assertFalse(Site::query()->archived()->whereKey($leftover->id)->exists());

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Garbage Archived Site', $html);
    }

    public function test_catalog_excludes_cancelled_bulk_live_leftovers(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $leftover = $this->site($publisher, [
            'site_name' => 'Cancelled Bulk Leftover',
            'domain' => 'cancelled-bulk-live.example',
            'site_url' => 'https://cancelled-bulk-live.example',
            'bulk_site_request_id' => $bulk->id,
        ]);
        $live = $this->site($publisher, [
            'site_name' => 'Independent Live Site',
            'domain' => 'independent-live.example',
            'site_url' => 'https://independent-live.example',
        ]);

        $this->assertFalse($leftover->isCatalogVisible());
        $this->assertTrue($live->isCatalogVisible());
        $this->assertSame(1, Site::query()->catalogVisible()->count());

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Independent Live Site', $html);
        $this->assertStringNotContainsString('Cancelled Bulk Leftover', $html);
    }

    public function test_cancelled_bulk_leftover_does_not_occupy_domain(): void
    {
        $publisher = $this->userWithRole('publisher');
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $leftover = $this->site($publisher, [
            'domain' => 'relist-after-cancel.example',
            'site_url' => 'https://relist-after-cancel.example',
            'bulk_site_request_id' => $bulk->id,
            'archived_at' => now(),
            'active' => false,
        ]);

        $this->assertTrue($leftover->isFromCancelledBulk());
        $this->assertNull(Site::findOccupyingDomain('relist-after-cancel.example'));
        $this->assertNull(Site::findOccupyingDomain('www.relist-after-cancel.example'));
    }

    public function test_release_deletes_unused_cancelled_leftover_and_tombstones_ordered_one(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 2,
        ]);
        $unused = $this->site($publisher, [
            'domain' => 'unused-cancel.example',
            'site_url' => 'https://unused-cancel.example',
            'bulk_site_request_id' => $bulk->id,
            'archived_at' => now(),
            'active' => false,
        ]);
        $ordered = $this->site($publisher, [
            'domain' => 'ordered-cancel.example',
            'site_url' => 'https://ordered-cancel.example',
            'bulk_site_request_id' => $bulk->id,
            'archived_at' => now(),
            'active' => false,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CANCEL-1',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $ordered->id,
            'site_name' => $ordered->site_name,
            'site_url' => $ordered->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 40,
        ]);

        $this->assertSame(1, Site::releaseCancelledBulkDomain('unused-cancel.example', $publisher->id));
        $this->assertDatabaseMissing('sites', ['id' => $unused->id]);

        $this->assertSame(1, Site::releaseCancelledBulkDomain('ordered-cancel.example', $publisher->id));
        $this->assertSame('cancelled-'.$ordered->id.'.invalid', $ordered->fresh()->domain);
        $this->assertNull(Site::findOccupyingDomain('ordered-cancel.example'));
    }
}
