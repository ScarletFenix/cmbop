<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\HomepageAiVisibility;
use App\Support\RobotsTxt;
use App\Support\ViewerCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class HomepageAiVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_homepage_has_answer_first_copy_facts_faq_and_schema(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('The guest post marketplace for verified publisher sites.', $html);
        $this->assertStringContainsString('SEOLinkBuildings is a European guest-post and link building marketplace with verified publishers.', $html);
        $this->assertStringContainsString('Teqno Ltd', $html);
        $this->assertStringContainsString('17392540', $html);
        $this->assertStringContainsString('20 Wenlock Road', $html);
        $this->assertStringContainsString('M. Arslan', $html);
        $this->assertStringContainsString('founded in 2024', $html);
        $this->assertStringContainsString('Publishers pay nothing', $html);
        $this->assertStringContainsString('500+ publishers', $html);
        $this->assertStringContainsString('held until you approve', $html);
        $this->assertStringContainsString('What is the best guest post marketplace for Europe?', $html);
        $this->assertStringContainsString('Can I buy German backlinks on SEOLinkBuildings?', $html);
        $this->assertStringContainsString('How much do sponsored articles in Spain cost?', $html);
        $this->assertStringContainsString('Do publishers pay to list on SEOLinkBuildings?', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('"@type":"Service"', $html);
        $this->assertStringContainsString('"@type":"OfferCatalog"', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
        $this->assertStringContainsString('"@type":"WebSite"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"@type":"SearchAction"', $html);
        $this->assertStringNotContainsString('"@type":"SoftwareApplication"', $html);
        $this->assertStringNotContainsString('"price":"0"', $html);
        $this->assertStringContainsString('"price":"20"', $html);
        $this->assertStringContainsString('"lowPrice":"20"', $html);
        $this->assertStringContainsString('from €20', $html);
        $this->assertStringContainsString('Marketplace placements start from', $html);
        $this->assertStringContainsString('data-home-from-price', $html);
        $this->assertStringNotContainsString('€52', $html);
        $this->assertStringNotContainsString('Topurlz', $html);
        $this->assertStringContainsString('/llms.txt', $html);
        $this->assertStringContainsString('/guest-post-prices-europe', $html);
        $this->assertStringContainsString('/guest-posts-germany', $html);
        $this->assertStringContainsString('/guest-posts-spain', $html);
        $this->assertStringContainsString('/is-seolinkbuildings-safe', $html);
        $this->assertStringContainsString('/about', $html);
        $this->assertStringContainsString('/contact', $html);
        $this->assertStringNotContainsString('advertiser/catalog', $html);

        foreach (HomepageAiVisibility::faqItems() as $item) {
            $this->assertStringContainsString($item['q'], $html);
            $this->assertStringContainsString($item['a'], $html);
            $words = preg_split('/\s+/u', trim($item['a'])) ?: [];
            $this->assertGreaterThanOrEqual(40, count($words), $item['q']);
        }
    }

    public function test_marketplace_from_price_is_always_20_eur_not_live_catalog_min(): void
    {
        $this->assertSame(20.0, HomepageAiVisibility::marketplaceFromEur());
        $this->assertSame('€20', HomepageAiVisibility::formatFromEur());

        $empty = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-home-from-price', $empty);
        $this->assertStringContainsString('from €20', $empty);
        $this->assertStringContainsString('"lowPrice":"20"', $empty);
        $this->assertStringNotContainsString('Each listing shows its EUR price before you order.', $empty);

        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        Site::create([
            'publisher_id' => $user->id,
            'site_name' => 'Floor Site',
            'site_url' => 'https://floor-site.de',
            'domain' => 'floor-site.de',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'Technology',
            'price' => 5,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Floor listing',
            'verified' => true,
            'active' => true,
        ]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame('€20', HomepageAiVisibility::formatFromEur());
        $this->assertStringContainsString('from €20', $html);
        $this->assertStringContainsString('"price":"20"', $html);
        $this->assertStringContainsString('"lowPrice":"20"', $html);
        $this->assertStringNotContainsString('from €6', $html);
        $this->assertStringNotContainsString('"lowPrice":"6"', $html);
        $this->assertStringNotContainsString('"price":"6"', $html);
    }

    public function test_public_faq_pricing_and_llms_use_from_20_not_stale_floors(): void
    {
        $faq = $this->get('/faq')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace placements on SEOLinkBuildings start from €20', $faq);
        $this->assertStringContainsString('Marketplace placements start from €20, prices are shown in EUR', $faq);
        $this->assertStringNotContainsString('start at €52', $faq);

        $pricing = $this->get('/pricing')->assertOk()->getContent();
        $this->assertStringContainsString('slb-price-from', $pricing);
        $this->assertStringContainsString('€20', $pricing);
        $this->assertStringContainsString('"lowPrice":"20"', $pricing);
        $this->assertStringNotContainsString('"lowPrice":"50"', $pricing);

        $llms = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace placements: start from €20.', $llms);
        $this->assertStringNotContainsString('start from a low EUR amount', $llms);
    }

    public function test_locale_home_does_not_get_english_faq_wall(): void
    {
        $html = $this->get('/de')->assertOk()->getContent();
        $this->assertStringNotContainsString('What is the best guest post marketplace for Europe?', $html);
        $this->assertStringNotContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('"@type":"Service"', $html);
        $this->assertStringContainsString('"@type":"WebSite"', $html);
    }

    public function test_robots_allow_llms_and_microsoft_preview(): void
    {
        $txt = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('User-agent: MicrosoftPreview', $txt);
        $this->assertStringContainsString('Allow: /llms.txt', $txt);
        $this->assertStringContainsString('Allow: /about', $txt);
        $this->assertStringContainsString('Allow: /faq', $txt);
        $this->assertStringContainsString('User-agent: GPTBot', $txt);
        $this->assertSame(
            RobotsTxt::render('https://seolinkbuildings.com'),
            (string) file_get_contents(public_path('robots.txt'))
        );
    }

    public function test_google_extended_and_microsoft_preview_see_eur(): void
    {
        $extended = Request::create('/', 'GET');
        $extended->headers->set('User-Agent', 'Mozilla/5.0 (compatible; Google-Extended)');
        $this->assertTrue(app(ViewerCountry::class)->isPublicCrawler($extended));

        $preview = Request::create('/', 'GET');
        $preview->headers->set('User-Agent', 'Mozilla/5.0 (compatible; MicrosoftPreview/2.0)');
        $this->assertTrue(app(ViewerCountry::class)->isPublicCrawler($preview));
    }
}
