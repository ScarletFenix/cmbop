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
        $this->assertStringContainsString('Add link insertion', $html);
    }

    public function test_link_insertion_tab_filters_sites_and_uses_advertiser_price(): void
    {
        $this->site('gp-hidden');
        $li = $this->site('li-shown', [
            'accepts_link_insertions' => true,
            'listing_link_insertion_price' => 40,
            'link_insertion_price' => 40,
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
    }

    public function test_link_insertion_tab_empty_state(): void
    {
        $this->site('gp-only-empty');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['product' => 'link_insertion']))
            ->assertOk()
            ->assertSee(__('messages.catalog_empty_link_insertions'), false)
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

    public function test_localized_marketplace_translates_tabs(): void
    {
        $html = $this->get('/de/marktplatz')->assertOk()->getContent();
        $this->assertStringContainsString('Gastbeiträge', $html);
        $this->assertStringContainsString('Link-Einfügungen', $html);
        $this->assertStringContainsString('Demnächst', $html);
        $this->assertStringContainsString('/de/link-insertion', $html);
    }
}
