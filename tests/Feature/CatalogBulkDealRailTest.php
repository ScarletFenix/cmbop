<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk discount deals are shown in fixed batches of six with a centered page
 * pager (← 1 2 3 → + Page X of Y), a slow autoplay slideshow, and a site
 * search beside Hide — not a wrapping grid or a horizontal scrollbar rail.
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

    public function test_the_deals_render_as_a_paged_batch_rail(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->makeBulkSite($i);
        }

        $html = $this->catalogHtml();

        $this->assertStringContainsString('catalog-bulk-rail', $html);
        $this->assertStringContainsString('data-bulk-track', $html);
        $this->assertStringContainsString('data-bulk-page-size="6"', $html);
        $this->assertStringContainsString('data-bulk-pager', $html);
        $this->assertStringContainsString('data-bulk-page-label', $html);

        // The grid columns are what let the section wrap onto extra rows.
        $this->assertStringNotContainsString('<div class="col-md-4 col-lg-3">', $html);
    }

    public function test_the_header_counts_deals_and_offers_search_beside_hide(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->makeBulkSite($i);
        }

        $html = $this->catalogHtml();

        $this->assertStringContainsString('catalog-bulk-count">7<', $html);
        $this->assertStringContainsString('data-bulk-search', $html);
        $this->assertStringContainsString('placeholder="Search deal by site"', $html);
        $this->assertStringContainsString('data-bulk-toggle', $html);
        $this->assertStringContainsString('No bulk deal for this site.', $html);
        $this->assertStringContainsString('aria-label="Previous bulk deals page"', $html);
        $this->assertStringContainsString('aria-label="Next bulk deals page"', $html);
    }

    public function test_a_deal_card_shows_real_host_for_search_and_display(): void
    {
        $this->makeBulkSite(1);

        $html = $this->catalogHtml();

        // Locked policy: bulk rail is a limited unmasked surface — real host +
        // name, and search matches that same text (not table mask helpers).
        // (Main table may still mask the same site — that dual face is intentional.)
        $this->assertStringContainsString('bulk-deal-card__host', $html);
        $this->assertStringContainsString('>bulk-deal-1.example<', $html);
        $this->assertStringContainsString('data-bulk-search-text="bulk-deal-1.example bulk deal site 1"', $html);
        $this->assertStringContainsString('data-bulk-deal-card', $html);
        $this->assertMatchesRegularExpression(
            '/bulk-deal-card__host[^>]*>bulk-deal-1\.example</',
            $html
        );
    }

    public function test_the_rail_keeps_a_six_up_grid_without_horizontal_scroll(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->makeBulkSite($i);
        }

        $html = $this->catalogHtml();
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertSame(12, substr_count($html, 'bulk-deal-card__cta'));

        $this->assertMatchesRegularExpression(
            '/\.catalog-bulk-rail \{[^}]*grid-template-columns: repeat\(6,/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-bulk-rail \{[^}]*overflow-x:\s*auto;/s',
            $css
        );
        $this->assertStringContainsString('justify-content: center', $css);
        $this->assertStringContainsString('.catalog-bulk-pager', $css);
        // Mist wash stays lighter than brand-primary-tint (#f4fbfb), with hover
        // colour/shadow feedback — no translateY (that shifted the rail layout).
        $this->assertStringContainsString(
            'background: linear-gradient(180deg, #fbfdfe 0%, #ffffff 62%)',
            $css
        );
        $this->assertStringNotContainsString(
            'background: linear-gradient(180deg, var(--brand-primary-tint, #f4fbfb) 0%, var(--surface-1, #fff) 100%)',
            $css
        );
        $this->assertStringContainsString('.catalog-bulk-section .bulk-deal-card:hover', $css);
        $this->assertStringContainsString('border-color: rgba(26, 88, 94, 0.28)', $css);
        $this->assertStringNotContainsString('transform: translateY(-3px)', $css);
    }

    public function test_the_rail_script_pages_searches_and_autoplays(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function initBulkDealRail(', $js);
        $this->assertStringContainsString("'catalog.bulkDeals.collapsed'", $js);
        $this->assertStringContainsString('data-bulk-page-size', $js);
        $this->assertStringContainsString('Page ', $js);
        $this->assertStringContainsString('of ', $js);
        $this->assertStringContainsString('AUTOPLAY_MS', $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('data-bulk-search-text', $js);
        $this->assertStringContainsString('is-bulk-match', $js);
        $this->assertStringContainsString('function canAutoplay(', $js);
        $this->assertStringContainsString('pointerInside', $js);

        // Blocked localStorage must not take the toggle down with it.
        $this->assertStringContainsString('function bulkRailReadCollapsed(', $js);
        $this->assertStringContainsString('return false;', $js);

        // Bulk CTAs pass a fixed pack (data-bulk-qty) into addToCart.
        $this->assertStringContainsString('cartOptions.bulk = true', $js);
        $this->assertStringContainsString('button.dataset.bulkQty', $js);
    }

    public function test_bulk_deals_sit_below_spendable_line_and_above_catalog_heading(): void
    {
        $this->makeBulkSite(1);

        $html = $this->catalogHtml();

        $bonusPos = strpos($html, 'Apply bonus at checkout.');
        $bulkPos = strpos($html, 'data-bulk-rail');
        $headingPos = strpos($html, 'fw-semibold">Catalog</h2>');
        $resultsPos = strpos($html, 'id="catalogResults"');

        $this->assertNotFalse($bulkPos);
        $this->assertNotFalse($headingPos);
        $this->assertNotFalse($resultsPos);
        $this->assertLessThan($headingPos, $bulkPos);
        $this->assertLessThan($resultsPos, $bulkPos);

        // When a welcome/bonus balance exists, spendable copy must precede the rail.
        if ($bonusPos !== false) {
            $this->assertLessThan($bulkPos, $bonusPos);
        }
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
