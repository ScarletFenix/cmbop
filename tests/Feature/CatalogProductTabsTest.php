<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CartPricingService;
use App\Services\Catalog\CatalogFilterStatus;
use App\Services\Catalog\CatalogUrlQuery;
use App\Support\LinkInsertionProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogProductTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        config(['content_moderation.enabled' => false]);

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);

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

    private function site(string $slug, array $extra = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Tab '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Product tab fixture.',
            'verified' => true,
            'active' => true,
        ], $extra));
    }

    public function test_product_is_allowlisted_for_share_urls(): void
    {
        $this->assertContains('product', CatalogUrlQuery::KEYS);
        $this->assertContains('product', CatalogFilterStatus::QUERY_KEYS);
        $js = file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertNotFalse($js);
        $this->assertStringContainsString("'product'", $js);
        $this->assertStringContainsString('function syncProductTabs', $js);
        $this->assertStringContainsString('data-tab-count-guest', $js);
        $this->assertStringContainsString('catalog-results-count__n', $js);
        $this->assertStringNotContainsString('strong class="text-dark"', $js);
    }

    public function test_catalog_default_tab_is_guest_posts_and_shows_both_sites(): void
    {
        $this->site('gp-only');
        $this->site('li-also', [
            'accepts_link_insertions' => true,
            'listing_link_insertion_price' => 40,
            'link_insertion_price' => 40,
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-product-tabs', $html);
        $this->assertStringContainsString('Guest posts', $html);
        $this->assertStringContainsString('Link insertions', $html);
        $this->assertStringContainsString('Banners / Text links', $html);
        $this->assertStringContainsString('Coming soon', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString('product=guest_post', $html);
        $this->assertStringContainsString('product=link_insertion', $html);
        $this->assertStringContainsString('Tab gp-only', $html);
        $this->assertStringContainsString('Tab li-also', $html);
        $this->assertStringContainsString('data-product-type="guest_post"', $html);
        $this->assertStringContainsString('Guest post', $html);
        $this->assertStringContainsString('Link insertion', $html);
        $this->assertStringContainsString('catalog-dual-price', $html);
        $this->assertStringNotContainsString('Also available as link insertion', $html);
        $this->assertStringNotContainsString('Add link insertion', $html);
        $this->assertStringContainsString('Payment held until your link is live', $html);
        $this->assertStringContainsString('data-catalog-select', $html);
        $this->assertStringContainsString('Save site', $html);
        $this->assertStringContainsString('Hide site', $html);
        $this->assertStringNotContainsString('Link insertions from', $html);
        $this->assertStringContainsString('data-catalog-tab-count="guest_post"', $html);
        $this->assertMatchesRegularExpression('/data-catalog-tab-count="guest_post"[^>]*>2</', $html);
        $this->assertMatchesRegularExpression('/data-catalog-tab-count="link_insertion"[^>]*>1</', $html);
    }

    public function test_link_insertion_tab_filters_sites_and_uses_advertiser_price(): void
    {
        $this->site('gp-hidden');
        $li = $this->site('li-shown', [
            'accepts_link_insertions' => true,
            'listing_link_insertion_price' => 40,
            'link_insertion_price' => 40,
            'link_insertion_delivery_days' => 5,
            'link_insertion_min_article_age_days' => 30,
            'link_insertion_link_type' => 'nofollow',
        ]);

        $priced = app(CartPricingService::class)->priceForAdvertiser(
            $li,
            null,
            1,
            'none',
            false,
            null,
            LinkInsertionProduct::TYPE
        );
        $advertiserPrice = (float) $priced['base'];

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'product' => LinkInsertionProduct::TYPE,
                'country' => 'de',
            ]))
            ->assertOk()
            ->assertSee('Tab li-shown', false)
            ->assertDontSee('Tab gp-hidden', false)
            ->assertSee(__('messages.catalog_tab_link_insertions'), false)
            ->getContent();

        $this->assertStringContainsString('data-catalog-product="link_insertion"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('country=de', $html);
        $this->assertStringContainsString('product=guest_post', $html);
        $this->assertStringContainsString('data-product-type="link_insertion"', $html);
        $this->assertStringNotContainsString('data-product-type="guest_post"', $html);
        $this->assertStringNotContainsString('Add link insertion', $html);
        $this->assertStringContainsString('Add to cart', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-product-type="link_insertion"[^>]*data-publisher-price=/',
            $html
        );
        $this->assertStringNotContainsString('(publisher)', $html);
        $this->assertStringContainsString(number_format($advertiserPrice, 2), $html);
        $this->assertStringContainsString(
            'data-inventory-from="'.number_format($advertiserPrice, 2, '.', '').'"',
            $html
        );
        $this->assertStringContainsString('Optional Link guarantee at checkout', $html);
        $this->assertStringContainsString(__('messages.catalog_tab_explainer_link_insertions'), $html);
        $this->assertStringContainsString(__('messages.catalog_li_guarantee_badge'), $html);
        $this->assertStringContainsString(__('messages.catalog_li_min_article_age', ['count' => 30]), $html);
        $this->assertStringContainsString('NoFollow', $html);
        $this->assertStringContainsString(__('messages.catalog_turnaround_many', ['count' => 5]), $html);
        $this->assertStringContainsString('Banners and text links are coming soon', $html);
        $this->assertStringNotContainsString('Link insertions from', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*data-site-name-label/', $html);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*catalog-site-name[^"]*"[^>]*data-site-name-label/',
            $html
        );
        $this->assertMatchesRegularExpression('/data-catalog-tab-count="link_insertion"[^>]*>1</', $html);
        $this->assertMatchesRegularExpression('/data-catalog-tab-count="guest_post"[^>]*>2</', $html);
    }

    public function test_link_insertion_tab_empty_state(): void
    {
        $this->site('gp-only-empty');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['product' => 'link_insertion']))
            ->assertOk()
            ->assertSee(__('messages.catalog_empty_link_insertions'), false)
            ->assertSee(__('messages.catalog_try_guest_posts'), false)
            ->assertSee(__('messages.catalog_clear_filters'), false)
            ->assertDontSee('Tab gp-only-empty', false);
    }

    public function test_guest_post_query_matches_default_catalog(): void
    {
        $this->site('same-as-default');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['product' => 'guest_post']))
            ->assertOk()
            ->assertSee('Tab same-as-default', false)
            ->assertSee('data-product-type="guest_post"', false);
    }

    public function test_marketplace_tabs_link_to_link_insertion_lander(): void
    {
        $html = $this->get('/marketplace')->assertOk()->getContent();
        $this->assertStringContainsString('catalog-product-tabs', $html);
        $this->assertStringContainsString('Guest posts', $html);
        $this->assertStringContainsString('/link-insertion', $html);
        $this->assertStringContainsString('Coming soon', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/catalog-product-tabs__tab is-disabled[^>]*href=/',
            $html
        );
    }

    public function test_catalog_followup_chrome_and_compare_fields(): void
    {
        $this->site('li-compare', [
            'accepts_link_insertions' => true,
            'listing_link_insertion_price' => 40,
            'link_insertion_price' => 40,
            'link_insertion_delivery_days' => 5,
            'link_type' => 'dofollow',
            'publication_time' => 'permanent',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-sticky-chrome', $html);
        $this->assertStringContainsString('id="catalogActiveFiltersHost"', $html);
        $this->assertStringContainsString('id="catalogFiltersToggle"', $html);
        $this->assertStringContainsString('id="catalogCompareBar"', $html);
        $this->assertStringContainsString('id="catalogCompareModal"', $html);
        $this->assertStringContainsString('catalog-skeleton', $html);
        $this->assertStringContainsString('DoFollow', $html);
        $this->assertStringContainsString('Permanent', $html);
        $this->assertStringContainsString('Payment held until your link is live', $html);
        $this->assertMatchesRegularExpression('/data-compare-gp-price="[^"]+"/', $html);
        $this->assertStringNotContainsString('data-compare-publisher', $html);
        $this->assertDoesNotMatchRegularExpression('/data-compare-[a-z-]+="[^"]*publisher/i', $html);
        $js = file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('function catalogSelectRestore', $js);
        $this->assertStringContainsString('catalogCompareModal', $js);
        $this->assertStringContainsString('const COMPARE_LIMIT = 4', $js);
        $this->assertStringContainsString('btn.click()', $js);
        $this->assertStringContainsString(':focus-visible', (string) file_get_contents(public_path('assets/css/catalog.css')));
    }

    public function test_localized_marketplace_translates_tabs(): void
    {
        $html = $this->get('/de/marktplatz')->assertOk()->getContent();
        $this->assertStringContainsString('Gastbeiträge', $html);
        $this->assertStringContainsString('Link-Einfügungen', $html);
        $this->assertStringContainsString('Demnächst', $html);
        $this->assertStringContainsString('/de/link-insertion', $html);
    }
}
