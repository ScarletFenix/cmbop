<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Array-shaped ?search[]= / ?q[]= used to 500 list pages via (string) cast
 * or LIKE interpolation. Ignore non-strings (same as admin payments).
 */
class SearchQueryHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $advertiser;

    private User $publisher;

    private User $marketer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->admin = $this->makeUser('admin');
        $this->advertiser = $this->makeUser('advertiser');
        $this->publisher = $this->makeUser('publisher');
        $this->marketer = $this->makeUser('marketing');

        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Search Guard Site',
            'site_url' => 'https://search-guard.example',
            'domain' => 'search-guard.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Site so publisher order search runs.',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_search_text_ignores_arrays_and_trims_strings(): void
    {
        $this->assertSame('', search_text(['injected']));
        $this->assertSame('', search_text(null));
        $this->assertSame('', search_text(12));
        $this->assertSame('INV-1', search_text('  INV-1  '));
    }

    public function test_admin_list_pages_ignore_array_search(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.deposits', ['search' => ['injected']]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('admin.withdrawals.data', ['search' => ['injected']]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->admin)
            ->get(route('admin.invoices.index', ['search' => ['injected']]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('admin.orders.data', [
                'search' => ['injected'],
                'status' => ['pending'],
                'payment_status' => ['paid'],
                'date_from' => ['2026-01-01'],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->admin)
            ->get(route('admin.community.index', ['q' => ['injected']]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.audiences.index', ['q' => ['injected']]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', ['status' => ['pending']]))
            ->assertOk();
    }

    public function test_advertiser_and_publisher_lists_ignore_array_search(): void
    {
        $this->actingAs($this->advertiser)
            ->get(route('advertiser.billing.index', ['search' => ['injected']]))
            ->assertOk();

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.orders', ['search' => ['injected']]))
            ->assertOk();

        $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.orders.list', ['search' => ['injected'], 'status' => ['pending']]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.balance.transactions', ['search' => ['injected']]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'search' => ['injected'],
                'country' => ['us'],
                'language' => ['en'],
                'category' => ['Technology'],
            ]))
            ->assertOk();

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.results', ['search' => ['injected']]))
            ->assertOk();

        $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.catalog.suggest', ['q' => ['injected']]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->publisher)
            ->get(route('publisher.billing.index', ['search' => ['injected']]))
            ->assertOk();

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data', ['search' => ['injected']]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_notifications_and_marketer_history_ignore_array_q(): void
    {
        $this->actingAs($this->advertiser)
            ->getJson(route('notifications.index', ['q' => ['injected']]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->advertiser)
            ->get(route('notifications.all', ['q' => ['injected']]))
            ->assertOk();

        $this->actingAs($this->marketer)
            ->get(route('marketing.history', ['q' => ['injected'], 'action' => ['site.activated']]))
            ->assertOk();
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
