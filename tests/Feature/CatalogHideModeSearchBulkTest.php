<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogHideModeSearchBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function advertiser(array $attrs = []): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function site(string $domain, string $name, array $extra = []): Site
    {
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $publisher->roles()->attach($pubRole->id);

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain.'/blog',
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Hide mode search/bulk test.',
            'verified' => true,
            'active' => true,
        ], $extra));
    }

    public function test_hide_mode_allows_free_domain_search_with_masked_name_on_results(): void
    {
        $site = $this->site('free-domain-search.example', 'Free Domain Search Brand');
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.catalog', ['search' => 'free-domain-search']))
            ->assertOk()
            ->getContent();

        // Result is not blocked — row exists — but plaintext identity stays masked.
        $this->assertStringContainsString('data-id="'.$site->id.'"', $html);
        $this->assertStringNotContainsString('Free Domain Search Brand', $html);
        $this->assertStringNotContainsString('free-domain-search.example', $html);
        $this->assertStringContainsString('Show site name and URL', $html);
    }

    public function test_hide_mode_name_search_never_blocks_results(): void
    {
        $site = $this->site('name-search-open.example', 'Open Name Search Weekly');
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.catalog', ['search' => 'Open Name Search']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-id="'.$site->id.'"', $html);
        $this->assertStringNotContainsString('Open Name Search Weekly', $html);
    }

    public function test_bulk_deals_show_real_host_even_in_hide_mode(): void
    {
        $domain = 'bulk-always-visible.example';
        $this->site($domain, 'Bulk Always Visible Brand', [
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 15,
        ]);

        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('bulk-deal-card', $html);
        $this->assertStringContainsString($domain, $html);
        $this->assertStringContainsString('Bulk Always Visible Brand', $html);
    }

    public function test_copy_tracker_exempts_bulk_deal_rail(): void
    {
        $js = file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('bulk-deal-card', $js);
        $this->assertStringContainsString('do not count toward strikes', $js);
    }

    public function test_cart_line_keeps_real_site_name_in_hide_mode(): void
    {
        $site = $this->site('cart-real-name.example', 'Cart Real Name Brand');
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);

        $this->actingAs($user)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'quantity' => 1,
            ])
            ->assertOk();

        $cart = session('cart', []);
        $this->assertNotEmpty($cart);
        $line = collect($cart)->first(fn ($row) => (int) ($row['id'] ?? 0) === (int) $site->id);
        $this->assertNotNull($line);
        $this->assertSame('Cart Real Name Brand', $line['name'] ?? null);
    }
}
