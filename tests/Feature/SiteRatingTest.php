<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SiteRatingTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Rated Site',
            'site_url' => 'https://rated.example',
            'domain' => 'rated.example',
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
        ]);
    }

    private function completedOrderItem(User $advertiser, Site $site, string $status = 'completed'): OrderItem
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
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

    public function test_rating_requires_completed_order(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'review');

        $this->actingAs($advertiser)->postJson(route('advertiser.ratings.store'), [
            'order_item_id' => $item->id,
            'rating' => 5,
        ])->assertStatus(422);
    }

    public function test_rating_requires_paid_order(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'completed');
        $item->order->update(['payment_status' => 'pending']);

        $this->actingAs($advertiser)->postJson(route('advertiser.ratings.store'), [
            'order_item_id' => $item->id,
            'rating' => 5,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('site_ratings', [
            'order_item_id' => $item->id,
        ]);
    }

    public function test_advertiser_can_rate_after_completed_order(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'completed');

        $this->actingAs($advertiser)->postJson(route('advertiser.ratings.store'), [
            'order_item_id' => $item->id,
            'rating' => 5,
            'comment' => 'Great publisher',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('site_ratings', [
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_item_id' => $item->id,
            'rating' => 5,
            'status' => 'approved',
        ]);

        $site->refresh();
        $this->assertSame(5.0, (float) $site->rating_avg);
        $this->assertSame(1, (int) $site->rating_count);
    }

    public function test_completed_orders_count_refreshes(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $this->completedOrderItem($advertiser, $site, 'completed');
        $this->completedOrderItem($advertiser, $site, 'completed');

        Site::refreshCompletedOrdersCount($site->id);
        $site->refresh();

        $this->assertSame(2, (int) $site->completed_orders_count);
        $this->assertSame('2 completed orders', $site->completedOrdersLabel());
    }

    public function test_sites_table_has_completed_orders_count_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('sites', 'completed_orders_count'),
            'sites.completed_orders_count must exist so order approval can refresh the counter'
        );
    }

    public function test_refresh_completed_orders_count_is_safe_without_column(): void
    {
        // Simulate a deploy that has not migrated the counter yet.
        Schema::table('sites', function ($table) {
            $table->dropColumn('completed_orders_count');
        });

        $this->assertFalse(Site::hasSitesColumn('completed_orders_count'));

        Site::refreshCompletedOrdersCount(1); // must not throw

        // Restore for later tests in this class / process.
        Schema::table('sites', function ($table) {
            $table->unsignedInteger('completed_orders_count')->default(0);
        });
    }

    public function test_admin_can_hide_rating_and_aggregate_excludes_it(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'completed');

        $rating = SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'rating' => 5,
            'status' => SiteRating::STATUS_APPROVED,
        ]);
        SiteRating::refreshSiteAggregate($site->id);

        $this->actingAs($admin)->putJson(route('admin.site-ratings.update', $rating->id), [
            'status' => 'hidden',
        ])->assertOk();

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.rating_updated')->count());

        $this->actingAs($admin)->putJson(route('admin.site-ratings.update', $rating->id), [
            'status' => 'hidden',
        ])->assertOk();

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.rating_updated')->count());

        $rating->refresh();
        $this->assertFalse((bool) $rating->is_admin);
        $this->assertSame($advertiser->id, (int) $rating->user_id);
        $this->assertSame($item->id, (int) $rating->order_item_id);

        $site->refresh();
        $this->assertSame(0, (int) $site->rating_count);
    }

    public function test_admin_store_creates_a_new_row_and_does_not_overwrite_advertiser_ratings(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $site = $this->site($publisher);
        $first = $this->completedOrderItem($advertiser, $site, 'completed');
        $second = $this->completedOrderItem($advertiser, $site, 'completed');

        $firstRating = SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_id' => $first->order_id,
            'order_item_id' => $first->id,
            'rating' => 5,
            'status' => SiteRating::STATUS_APPROVED,
        ]);
        $secondRating = SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_id' => $second->order_id,
            'order_item_id' => $second->id,
            'rating' => 3,
            'status' => SiteRating::STATUS_APPROVED,
        ]);
        SiteRating::refreshSiteAggregate($site->id);

        $this->actingAs($admin)->postJson(route('admin.site-ratings.store'), [
            'site_id' => $site->id,
            'rating' => 1,
            'comment' => 'Staff note',
            'status' => 'approved',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(3, SiteRating::query()->where('site_id', $site->id)->count());
        $this->assertSame(5, (int) $firstRating->fresh()->rating);
        $this->assertSame(3, (int) $secondRating->fresh()->rating);
        $this->assertFalse((bool) $firstRating->fresh()->is_admin);
        $this->assertTrue(
            SiteRating::query()
                ->where('site_id', $site->id)
                ->where('is_admin', true)
                ->where('rating', 1)
                ->exists()
        );

        $site->refresh();
        $this->assertSame(3, (int) $site->rating_count);
        $this->assertEqualsWithDelta(3.0, (float) $site->rating_avg, 0.01);
    }

    public function test_ratings_index_and_hide_survive_missing_aggregate_columns(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'completed');
        $rating = SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'rating' => 4,
            'status' => SiteRating::STATUS_APPROVED,
        ]);

        Schema::table('sites', function ($table) {
            $table->dropColumn(['rating_avg', 'rating_count']);
        });
        $this->assertFalse(Site::hasSitesColumn('rating_avg'));
        $this->assertFalse(Site::hasSitesColumn('rating_count'));

        $this->actingAs($admin)
            ->get(route('admin.site-ratings.index'))
            ->assertOk()
            ->assertSee('Publisher Ratings', false);

        $this->actingAs($admin)
            ->putJson(route('admin.site-ratings.update', $rating->id), [
                'status' => 'hidden',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        SiteRating::refreshSiteAggregate($site->id);

        Schema::table('sites', function ($table) {
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
        });
    }

    public function test_ratings_page_uses_named_rating_routes(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin)
            ->get(route('admin.site-ratings.index'))
            ->assertOk()
            ->assertSee('Add rating', false)
            ->getContent();

        $this->assertStringContainsString('RATING_UPDATE', $html);
        $this->assertStringContainsString('RATING_DESTROY', $html);
        $this->assertStringContainsString('__ID__', $html);
        $this->assertStringNotContainsString('`/admin/site-ratings/${btn.dataset.id}`', $html);
    }

    public function test_advertiser_cannot_change_a_hidden_rating(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'completed');

        $rating = SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'rating' => 5,
            'comment' => 'Great publisher',
            'status' => SiteRating::STATUS_APPROVED,
        ]);
        SiteRating::refreshSiteAggregate($site->id);

        $this->actingAs($admin)->putJson(route('admin.site-ratings.update', $rating->id), [
            'status' => 'hidden',
        ])->assertOk();

        $this->actingAs($advertiser)->postJson(route('advertiser.ratings.store'), [
            'order_item_id' => $item->id,
            'rating' => 1,
            'comment' => 'Trying to unhide',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->actingAs($advertiser)->postJson(route('advertiser.ratings.batch'), [
            'ratings' => [[
                'order_item_id' => $item->id,
                'rating' => 1,
                'comment' => 'Trying to unhide via batch',
            ]],
        ])->assertStatus(422)->assertJsonPath('success', false);

        $rating->refresh();
        $this->assertSame(SiteRating::STATUS_HIDDEN, $rating->status);
        $this->assertSame(5, (int) $rating->rating);
        $this->assertSame('Great publisher', $rating->comment);
        $this->assertSame(0, (int) $site->fresh()->rating_count);
    }

    public function test_edit_button_comment_is_not_double_escaped(): void
    {
        $publisher = User::factory()->create();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $item = $this->completedOrderItem($advertiser, $site, 'completed');

        SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $advertiser->id,
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'rating' => 4,
            'comment' => 'Good & "fast"',
            'status' => SiteRating::STATUS_APPROVED,
        ]);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.site-ratings.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-comment="Good &amp; &quot;fast&quot;"', $html);
        $this->assertStringNotContainsString('Good &amp;amp;', $html);
    }

    public function test_ratings_index_uses_nullsafe_site_access(): void
    {
        $blade = file_get_contents(resource_path('views/admin/site-ratings.blade.php'));

        $this->assertStringContainsString('$rating->site?->site_name', $blade);
        $this->assertStringContainsString('$rating->site?->domain', $blade);
        $this->assertStringNotContainsString('$rating->site->site_name', $blade);
        $this->assertStringNotContainsString('$rating->site->domain', $blade);
    }

    public function test_ratings_index_ok_when_q_is_array(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.site-ratings.index', ['q' => ['oops']]))
            ->assertOk()
            ->assertSee('Publisher Ratings', false);
    }

    public function test_admin_store_succeeds_when_activity_log_table_missing(): void
    {
        $publisher = User::factory()->create();
        $site = $this->site($publisher);

        Schema::dropIfExists('activity_logs');

        try {
            $this->actingAs($this->admin())->postJson(route('admin.site-ratings.store'), [
                'site_id' => $site->id,
                'rating' => 4,
                'comment' => 'Staff note',
                'status' => 'approved',
            ])->assertOk()->assertJsonPath('success', true);

            $this->assertDatabaseHas('site_ratings', [
                'site_id' => $site->id,
                'rating' => 4,
                'is_admin' => 1,
            ]);
        } finally {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_15_150505_create_activity_logs_table.php',
                '--force' => true,
            ]);
        }
    }

    public function test_ratings_index_ok_when_table_missing(): void
    {
        Schema::dropIfExists('site_ratings');

        try {
            $this->assertFalse(Schema::hasTable('site_ratings'));

            $this->actingAs($this->admin())
                ->get(route('admin.site-ratings.index'))
                ->assertOk()
                ->assertSee('Publisher Ratings', false)
                ->assertSee('No ratings yet.', false);
        } finally {
            // DROP TABLE commits outside RefreshDatabase's transaction — put the
            // table back so later tests in this process still have a schema.
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_16_240000_create_site_ratings_table.php',
                '--force' => true,
            ]);
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_16_250000_tie_site_ratings_to_completed_orders.php',
                '--force' => true,
            ]);
        }
    }
}
