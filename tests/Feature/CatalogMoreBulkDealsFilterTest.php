<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogFilterStatus;
use App\Services\Catalog\CatalogUrlQuery;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * More drawer: teal mist theme + bulk_deals=1 listing filter.
 * Option 2: Spendable bulk rail is hidden while bulk_deals only is on.
 */
class CatalogMoreBulkDealsFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(string $name, bool $bulk): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example',
            'domain' => strtolower(str_replace(' ', '-', $name)).'.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Catalog more-filter fixture.',
            'verified' => true,
            'active' => 1,
            'bulk_discount_enabled' => $bulk ? 1 : 0,
            'bulk_discount_percent' => $bulk ? 12 : null,
        ]);
    }

    public function test_bulk_deals_param_is_allowlisted_in_url_query(): void
    {
        $this->assertContains('bulk_deals', CatalogUrlQuery::KEYS);
    }

    public function test_more_drawer_is_themed_and_exposes_bulk_deals_select(): void
    {
        $this->makeSite('Plain Site', false);

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-more-drawer', $html);
        $this->assertStringContainsString('catalog-more-drawer__inner', $html);
        $this->assertStringContainsString('name="bulk_deals"', $html);
        $this->assertStringContainsString('Bulk deals only', $html);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('.catalog-more-drawer__inner', $css);
        $this->assertStringContainsString('var(--brand-primary-tint', $css);
        $this->assertStringContainsString('var(--brand-primary-border', $css);
    }

    public function test_bulk_deals_only_filter_limits_listing_and_hides_rail(): void
    {
        $this->makeSite('Bulk Only Site', true);
        $this->makeSite('Regular Listing Site', false);

        $filtered = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['bulk_deals' => '1']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bulk Only Site', $filtered);
        $this->assertStringNotContainsString('Regular Listing Site', $filtered);
        $this->assertStringContainsString('>Bulk deals<', $filtered);
        $this->assertStringNotContainsString('data-bulk-rail', $filtered);
        $this->assertMatchesRegularExpression(
            '/name="bulk_deals"[^>]*>[\s\S]*?<option value="1"[^>]*selected/s',
            $filtered
        );

        $all = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bulk Only Site', $all);
        $this->assertStringContainsString('Regular Listing Site', $all);
        $this->assertStringContainsString('data-bulk-rail', $all);
    }

    public function test_live_client_wires_bulk_deals_and_hides_rail_on_filter(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));

        $this->assertStringContainsString("'bulk_deals'", $js);
        $this->assertStringContainsString("params.get('bulk_deals') === '1'", $js);
        $this->assertStringContainsString("label: 'Bulk deals'", $js);
        $this->assertStringContainsString("'bulk_deals'", implode(' ', [
            // moreKeys + live select list — both must mention the param.
            $js,
        ]));
        $this->assertMatchesRegularExpression(
            '/\[\'sponsored\', \'favorites_filter\', \'blacklist_filter\', \'bulk_deals\'\]/',
            $js
        );
        $this->assertStringContainsString("'bulk_deals'", $blade);
        $this->assertStringContainsString('bulk_deals', $blade);
    }

    public function test_bulk_deals_fragment_is_empty_when_bulk_only_filter_on(): void
    {
        $this->makeSite('Bulk Fragment Site', true);

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['bulk_deals' => '1']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-bulk-rail', $html);
        $this->assertStringNotContainsString('Bulk Fragment Site', $html);
    }

    public function test_empty_recovery_urls_preserve_bulk_deals_param(): void
    {
        $this->assertContains('bulk_deals', CatalogFilterStatus::QUERY_KEYS);

        $request = Request::create('/advertiser/catalog', 'GET', [
            'bulk_deals' => '1',
            'country' => 'de',
            'sponsored' => '1',
        ]);

        $kept = app(CatalogFilterStatus::class)
            ->catalogQuery($request, except: ['country', 'page']);

        $this->assertSame('1', $kept['bulk_deals'] ?? null);
        $this->assertSame('1', $kept['sponsored'] ?? null);
        $this->assertArrayNotHasKey('country', $kept);
    }
}
