<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk discount deals were a wrapping grid of cards, so the section grew with
 * the number of offers: twelve of them stacked into three rows and pushed the
 * results table most of a screen down. It is one scrolling row now, which costs
 * the same height whether two publishers join or twenty.
 */
class CatalogBulkDealRailTest extends TestCase
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

    private function makeBulkSite(int $index): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Bulk Deal Site '.$index,
            'site_url' => 'https://bulk-deal-'.$index.'.example',
            'domain' => 'bulk-deal-'.$index.'.example',
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
            'description' => 'A listing that joined the bulk discount programme.',
            'verified' => true,
            'active' => 1,
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
        ]);
    }

    private function catalogHtml(): string
    {
        return (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();
    }

    public function test_the_deals_render_as_one_scrolling_rail(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->makeBulkSite($i);
        }

        $html = $this->catalogHtml();

        $this->assertStringContainsString('catalog-bulk-rail', $html);
        $this->assertStringContainsString('data-bulk-track', $html);

        // The grid columns are what let the section wrap onto extra rows.
        $this->assertStringNotContainsString('<div class="col-md-4 col-lg-3">', $html);
    }

    public function test_the_header_counts_the_deals_and_offers_paging_and_collapse(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->makeBulkSite($i);
        }

        $html = $this->catalogHtml();

        $this->assertStringContainsString('catalog-bulk-count">7<', $html);
        $this->assertStringContainsString('data-bulk-scroll="prev"', $html);
        $this->assertStringContainsString('data-bulk-scroll="next"', $html);
        $this->assertStringContainsString('data-bulk-toggle', $html);
        $this->assertStringContainsString('aria-label="Show more bulk deals"', $html);
    }

    public function test_a_deal_card_shows_the_same_masked_identity_as_the_table(): void
    {
        $this->makeBulkSite(1);

        $html = $this->catalogHtml();

        // The rail used to print site_name raw, which publishers routinely set to
        // their domain — the one thing the results table is masking.
        $this->assertStringContainsString('bulk-deal-card__host', $html);
        $this->assertStringContainsString('bulk***.example', $html);
        $this->assertStringNotContainsString('>bulk-deal-1.example<', $html);
    }

    public function test_the_rail_keeps_one_row_whatever_the_deal_count(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->makeBulkSite($i);
        }

        $html = $this->catalogHtml();
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertSame(12, substr_count($html, 'bulk-deal-card__cta'));

        // Fixed basis plus horizontal overflow is what holds it to a single row.
        $this->assertMatchesRegularExpression('/\.catalog-bulk-rail \{[^}]*overflow-x: auto;/s', $css);
        $this->assertMatchesRegularExpression(
            '/\.catalog-bulk-section \.bulk-deal-card \{[^}]*flex: 0 0 15\.5rem;/s',
            $css
        );
        // Mist wash stays lighter than brand-primary-tint (#f4fbfb), with hover lift.
        $this->assertStringContainsString(
            'background: linear-gradient(180deg, #fbfdfe 0%, #ffffff 62%)',
            $css
        );
        $this->assertStringNotContainsString(
            'background: linear-gradient(180deg, var(--brand-primary-tint, #f4fbfb) 0%, var(--surface-1, #fff) 100%)',
            $css
        );
        $this->assertStringContainsString('.catalog-bulk-section .bulk-deal-card:hover', $css);
        $this->assertStringContainsString('transform: translateY(-3px)', $css);
    }

    public function test_the_rail_script_pages_and_remembers_a_collapsed_section(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function initBulkDealRail(', $js);
        $this->assertStringContainsString("'catalog.bulkDeals.collapsed'", $js);

        // Arrows are pointless when nothing is clipped, so they only show when the
        // track actually overflows.
        $this->assertStringContainsString("classList.toggle('is-scrollable', scrollable)", $js);

        // Blocked localStorage must not take the toggle down with it.
        $this->assertStringContainsString('function bulkRailReadCollapsed(', $js);
        $this->assertStringContainsString('return false;', $js);

        // Bulk CTAs pass a fixed pack (data-bulk-qty) into addToCart.
        $this->assertStringContainsString('cartOptions.bulk = true', $js);
        $this->assertStringContainsString('this.dataset.bulkQty', $js);
    }

    public function test_the_section_is_absent_when_no_publisher_joined(): void
    {
        $html = $this->catalogHtml();

        $this->assertStringNotContainsString('catalog-bulk-rail', $html);
    }

    public function test_rail_badge_follows_better_of_when_custom_beats_bulk(): void
    {
        $site = $this->makeBulkSite(1);
        $site->update([
            'bulk_discount_percent' => 15,
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $html = $this->catalogHtml();

        // Pack “now” floors at publisher payout; badge shows effective ~11.5%, not nominal 20/15.
        $this->assertMatchesRegularExpression('/bulk-deal-card__pct[\s\S]*?Sale −11\.5%/', $html);
        $this->assertStringNotContainsString('Sale −20%', $html);
        $this->assertStringNotContainsString('Bulk −15%', $html);
        $this->assertStringContainsString('Site sale applies on this pack', $html);
    }

    public function test_rail_badge_keeps_bulk_percent_when_bulk_beats_custom(): void
    {
        $site = $this->makeBulkSite(1);
        $site->update([
            'bulk_discount_percent' => 15,
            'custom_discount_percent' => 10,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $html = $this->catalogHtml();

        // Pack floors to the same €100 “now”; badge is effective, not nominal −15%.
        $this->assertMatchesRegularExpression(
            '/bulk-deal-card__pct[\s\S]*?−11\.5%/',
            $html
        );
        $this->assertStringNotContainsString('Sale −10%', $html);
        $this->assertStringNotContainsString('Sale −15%', $html);
        $this->assertStringNotContainsString('Sale −11.5%', $html);
    }
}
