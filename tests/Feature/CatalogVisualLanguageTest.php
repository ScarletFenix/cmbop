<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalog listed publisher sites as a wall of monospace domains and bare
 * numbers: "55" with nothing to say whether 55 was good, two lines of grey prose
 * per row, and the price wedged inside the Buy button. This pins the visual
 * language that replaced it.
 */
class CatalogVisualLanguageTest extends TestCase
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

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Visual Language Site',
            'site_url' => 'https://visual-language.example',
            'domain' => 'visual-language.example',
            'da' => 44,
            'dr' => 72,
            'traffic' => 42000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'A listing used to pin the catalog visual language.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    private function catalogHtml(array $query = []): string
    {
        return (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', $query))
            ->assertOk()
            ->getContent();
    }

    public function test_metrics_carry_a_bar_so_a_number_has_a_scale(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();

        // DR 72 of 100 is a 72% fill; DA 44 is 44%.
        $this->assertStringContainsString('catalog-metric__fill" style="width: 72%"', $html);
        $this->assertStringContainsString('catalog-metric__fill" style="width: 44%"', $html);

        // Past 70 the fill deepens, so standouts are visible without colour-coding
        // the whole scale — which would pass judgement on publisher inventory.
        $this->assertStringContainsString('catalog-metric is-standout', $html);

        // Screen readers get the number and its scale, not a bare digit.
        $this->assertStringContainsString('DR 72 out of 100', $html);
        $this->assertStringContainsString('DA 44 out of 100', $html);
    }

    public function test_traffic_is_compact_on_screen_and_exact_in_the_title(): void
    {
        $this->makeSite(['traffic' => 1450000]);

        $html = $this->catalogHtml();

        // A column of "1,450,000" crowds out everything beside it.
        $this->assertStringContainsString('>1.5M<', $html);
        $this->assertStringContainsString('1,450,000 monthly visits', $html);
    }

    public function test_traffic_uses_a_log_scale_so_small_sites_are_not_all_zero(): void
    {
        $this->makeSite(['traffic' => 800, 'domain' => 'small.example', 'site_url' => 'https://small.example']);

        $html = $this->catalogHtml();

        // On a linear scale against millions, 800 visits would render as an empty
        // bar and be indistinguishable from a site with none.
        $this->assertMatchesRegularExpression(
            '/catalog-metric__fill" style="width: (4[0-9]|5[0-9])(\.\d)?%"/',
            $html
        );
    }

    public function test_each_listing_gets_a_monogram_tile_from_the_label_on_screen(): void
    {
        $site = $this->makeSite();

        $html = $this->catalogHtml();

        // The label on screen is the masked "visu***.example", so the initials
        // are VI — the tile is built from what is already visible.
        $this->assertStringContainsString('catalog-tile catalog-tile--md', $html);
        $this->assertStringContainsString('catalog-tile catalog-tile--lg', $html);
        $this->assertMatchesRegularExpression('/catalog-tile--tone[1-6]/', $html);
        $this->assertStringContainsString('>VI</span>', $html);
        unset($site);
    }

    public function test_the_tile_never_reveals_a_masked_host(): void
    {
        // A site this advertiser has not revealed shows as "secr***.example".
        $this->makeSite([
            'site_name' => 'Secret Inventory',
            'site_url' => 'https://secret-inventory.example',
            'domain' => 'secret-inventory.example',
        ]);

        $html = $this->catalogHtml();

        $this->assertStringNotContainsString('secret-inventory.example', $html);
        // Initials come from the masked label, so "secr" gives SE — never SI.
        $this->assertStringContainsString('>SE</span>', $html);
    }

    public function test_the_price_has_its_own_block_beside_the_button(): void
    {
        $this->makeSite([
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $html = $this->catalogHtml();

        // The CTA used to read "Buy €113.00 €90.40" — three pieces of text in one
        // control. €100 + 13% fee = €113 list, 20% off = €90.40.
        $this->assertStringContainsString('catalog-price__pay base-price-display">€90.40', $html);
        $this->assertStringContainsString('catalog-price__list list-price-display', $html);
        $this->assertStringContainsString('>€113.00<', $html);
        $this->assertStringContainsString('catalog-price__offer', $html);
        $this->assertStringContainsString('20% off', $html);

        // The button now says what it does rather than carrying the number.
        $this->assertStringContainsString('>Add to cart</span>', $html);
    }

    public function test_a_listing_without_an_offer_hides_the_struck_price(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();

        $this->assertStringContainsString('list-price-display" hidden>', $html);
        $this->assertStringNotContainsString('catalog-price__offer', $html);
    }

    public function test_the_repeated_row_facts_are_chips_not_two_lines_of_prose(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();

        $this->assertStringContainsString('catalog-meta-chip', $html);
        $this->assertStringContainsString('3 dofollow', $html);
        $this->assertStringContainsString('>48h<', $html);

        // The old prose lines added height to every row and read slowly.
        $this->assertStringNotContainsString('Max 03 DoFollow links</div>', $html);
        $this->assertStringNotContainsString('Turnaround: 48h', $html);
    }

    public function test_the_vendor_raster_logos_are_gone_from_the_metric_columns(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();

        // ahref.jpeg and moz_da.png were 16px raster crops beside each number; the
        // column headers already name and explain both sources.
        $this->assertStringNotContainsString('ahref.jpeg', $html);
        $this->assertStringNotContainsString('moz_da.png', $html);
        $this->assertStringContainsString('Ahrefs Domain Rating', $html);
        $this->assertStringContainsString('Moz Domain Authority', $html);
    }

    public function test_sorting_and_paging_announce_that_results_are_updating(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        // Both are full reloads, so the click had no answer until the next
        // document painted.
        $this->assertStringContainsString('id="catalogResults"', $html);
        $this->assertStringContainsString('catalog-results-busy', $html);
        $this->assertStringContainsString('Updating results', $html);
        $this->assertStringContainsString('function markCatalogResultsBusy(', $js);

        // form.submit() does not fire a submit event, so the sort path has to
        // raise the state itself.
        $this->assertMatchesRegularExpression(
            '/function submitCatalogFilters\(\) \{[^}]*markCatalogResultsBusy\(\);/s',
            $js
        );
    }

    public function test_the_empty_state_draws_a_list_rather_than_an_error_glyph(): void
    {
        $html = $this->catalogHtml(['da_min' => 99]);

        $this->assertStringContainsString('No sites match these filters', $html);
        $this->assertStringContainsString('catalog-empty-art', $html);
        $this->assertStringContainsString('An empty list of publisher listings', $html);

        // A crossed-out filter in a circle read as something having gone wrong.
        $this->assertStringNotContainsString('catalog-empty-icon', $html);
        $this->assertStringNotContainsString('fa-filter-circle-xmark', $html);
    }

    public function test_the_new_visuals_are_shared_by_the_table_and_the_cards(): void
    {
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));

        // The table row and the card are near-duplicate markup, so anything that
        // renders in both belongs in a partial or it drifts.
        foreach ([
            // Three metrics per layout, everything else once per layout.
            'advertiser.partials.catalog-metric' => 6,
            'advertiser.partials.catalog-site-tile' => 2,
            'advertiser.partials.catalog-price' => 2,
            'advertiser.partials.catalog-meta-chips' => 2,
            'advertiser.partials.catalog-empty-art' => 2,
        ] as $partial => $expected) {
            $this->assertSame(
                $expected,
                substr_count($blade, $partial),
                $partial.' should be included by both the table and the card'
            );
        }
    }
}
