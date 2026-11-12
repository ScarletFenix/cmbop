<?php

namespace Tests\Feature;

use App\Support\BrandOrganization;
use App\Support\CommercialPageCluster;
use App\Support\EnglishCommercialLanders;
use App\Support\MoneyLanderRoutes;
use App\Support\PublicI18n;
use App\Support\ViewerCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AiVisibilityOnsiteFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_llms_txt_uses_teqno_and_lists_english_commercial_pages(): void
    {
        $body = $this->get('/llms.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Teqno Ltd', $body);
        $this->assertStringContainsString('17392540', $body);
        $this->assertStringContainsString('Founded: 2024', $body);
        $this->assertStringContainsString('20 Wenlock Road', $body);
        $this->assertStringNotContainsString('Topurlz', $body);
        $this->assertStringNotContainsString('16607074', $body);
        $this->assertStringContainsString('/for-agencies', $body);
        $this->assertStringContainsString('/sponsored-articles', $body);
        $this->assertStringContainsString('/link-insertion', $body);
        $this->assertStringContainsString('/alternatives', $body);
        $this->assertStringContainsString('/is-seolinkbuildings-safe', $body);
        $this->assertStringContainsString('/pl/', $body);
        $this->assertStringContainsString('/dk/', $body);
        $this->assertStringContainsString('/se/', $body);
        $this->assertStringContainsString('/no/', $body);
        $this->assertStringNotContainsString('/us/about', $body);
    }

    public function test_organization_same_as_omits_unverified_x_handle(): void
    {
        $sameAs = BrandOrganization::sameAs();
        foreach ($sameAs as $url) {
            $this->assertStringNotContainsString('x.com/', $url);
            $this->assertStringNotContainsString('twitter.com/', $url);
        }

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('https://x.com/seolinbuildings', $html);
        $this->assertStringNotContainsString('https://x.com/seolinkbuildings', $html);
        $this->assertStringContainsString('https://www.linkedin.com/company/seolinkbuildings', $html);
    }

    public function test_about_does_not_show_completed_placement_volume(): void
    {
        $html = $this->get('/about')->assertOk()->getContent();
        $this->assertStringNotContainsString('Completed placements on the platform', $html);
        $this->assertStringNotContainsString('about_page_proof_orders', $html);
        $this->assertLessThanOrEqual(160, mb_strlen((string) __('messages.meta_about_description')));
    }

    public function test_english_commercial_pages_are_indexable_with_hreflang_and_schema(): void
    {
        foreach (EnglishCommercialLanders::slugs() as $slug) {
            $page = EnglishCommercialLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee(e($page['meta_title']), false)
                ->assertSee('rel="canonical" href="'.url('/'.$slug).'"', false)
                ->assertSee('hreflang="en-GB"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertDontSee('name="robots" content="noindex', false)
                ->getContent();

            $this->assertStringContainsString('"@type":"WebPage"', $html, $slug);
            $this->assertStringContainsString('"@type":"FAQPage"', $html, $slug);
            if (in_array($slug, ['for-agencies', 'alternatives'], true)) {
                $this->assertStringContainsString('Teqno Ltd', $html, $slug);
            }
            $this->assertStringNotContainsString('Topurlz', $html, $slug);
            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']), $slug);

            $this->get('/de/'.$slug)->assertRedirect('/'.$slug);
            $this->assertSame(301, $this->get('/de/'.$slug)->status());
        }
    }

    public function test_english_commercial_aliases_redirect(): void
    {
        $this->assertTrue(MoneyLanderRoutes::englishOwnsUnprefixed('advertorial'));
        $this->assertFalse(MoneyLanderRoutes::englishOwnsUnprefixed('gastbeitrag-kaufen'));

        foreach (EnglishCommercialLanders::aliases() as $alias => $canonical) {
            $this->get('/'.$alias)->assertRedirect($canonical);
            $this->assertSame(301, $this->get('/'.$alias)->status(), $alias);
            $this->assertSame(1, self::getRouteCount($alias), 'duplicate GET /'.$alias.' (route-cache last-route-wins)');
        }

        $this->get('/advertorial')->assertRedirect('/sponsored-articles');
        $this->assertSame(301, $this->get('/advertorial')->status());
        $this->get('/advertorials')->assertRedirect('/sponsored-articles');
        $this->get('/sponsored-posts')->assertRedirect('/sponsored-articles');
        $this->get('/agencies')->assertRedirect('/for-agencies');
        $this->get('/marketplace-alternatives')->assertRedirect('/alternatives');
        $this->get('/de/agencies')->assertRedirect('/agencies');
        $this->get('/de/advertorial')->assertOk();
        $this->get('/at/advertorial')->assertOk();
        $this->get('/ch/advertorial')->assertOk();
        $this->assertSame(1, self::getRouteCount('de/advertorial'));
    }

    public function test_deploy_workflow_removes_stale_public_llms_txt(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $rsyncPos = strpos($yml, '"$APP_DIR/public/" "$PUBLIC_DIR/"');
        $rmPos = strpos($yml, 'rm -f "$PUBLIC_DIR/llms.txt"');
        $this->assertNotFalse($rsyncPos);
        $this->assertNotFalse($rmPos);
        $this->assertGreaterThan($rsyncPos, $rmPos);
        $this->assertStringNotContainsString('rm -rf "$PUBLIC_DIR"', $yml);
        $this->assertStringNotContainsString('rm -f "$PUBLIC_DIR/default.php"', $yml);
        $this->assertFileDoesNotExist(public_path('llms.txt'));
    }

    public function test_agency_cluster_pairs_english_with_locale_twins(): void
    {
        $this->assertTrue(CommercialPageCluster::hasEnglishAlternate('for-agencies'));
        $this->assertTrue(CommercialPageCluster::hasEnglishAlternate('agenturen'));
        $this->assertContains('en', CommercialPageCluster::locales('for-agencies'));
        $this->assertContains('de', CommercialPageCluster::locales('agenturen'));
        $this->assertSame('en', PublicI18n::moneyLanderXDefault('for-agencies'));
        $this->assertSame('en', PublicI18n::moneyLanderXDefault('agenturen'));

        $en = $this->get('/for-agencies')->assertOk()->getContent();
        $this->assertStringContainsString('hreflang="de" href="'.url('/de/agenturen').'"', $en);
        $this->assertStringContainsString('hreflang="es" href="'.url('/es/agencias').'"', $en);
        $this->assertStringContainsString('hreflang="x-default" href="'.url('/for-agencies').'"', $en);

        $de = $this->get('/de/agenturen')->assertOk()->getContent();
        $this->assertStringContainsString('hreflang="en-GB" href="'.url('/for-agencies').'"', $de);

        $esAlts = $this->get('/es/alternativas')->assertOk()->getContent();
        $this->assertStringContainsString('hreflang="en-GB" href="'.url('/alternatives').'"', $esAlts);
    }

    public function test_english_sitemap_lists_commercial_pages_and_robots_allow_them(): void
    {
        $en = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $de = $this->get('/sitemap-de.xml')->assertOk()->getContent();

        foreach (EnglishCommercialLanders::slugs() as $slug) {
            $this->assertStringContainsString('/'.$slug, $en, $slug);
            $this->assertStringNotContainsString('/de/'.$slug, $en, $slug);
            $this->assertStringNotContainsString('/'.$slug.'</loc>', $de, $slug);
        }
        $this->assertStringContainsString('/de/agenturen', $de);
        $this->assertStringContainsString('/for-agencies', $de);

        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Allow: /for-agencies', $robots);
        $this->assertStringContainsString('Allow: /sponsored-articles', $robots);
        $this->assertStringContainsString('Allow: /link-insertion', $robots);
        $this->assertStringContainsString('Allow: /alternatives', $robots);
    }

    public function test_marketplace_and_why_choose_state_european_focus(): void
    {
        $market = $this->get('/marketplace')->assertOk()->getContent();
        $this->assertStringContainsString('European guest-post', $market);
        $this->assertStringNotContainsString('Chinese, and Gulf', $market);
        $this->assertStringContainsString('/for-agencies', $market);
        $this->assertStringContainsString('/sponsored-articles', $market);
        $this->assertStringContainsString('/alternatives', $market);
        $this->assertStringContainsString('/guest-post-prices-europe', $market);
        $this->assertStringContainsString('/guest-posts-germany', $market);
        $this->assertStringContainsString('/guest-posts-poland', $market);

        $why = $this->get('/why-choose-us')->assertOk()->getContent();
        $this->assertStringContainsString('European guest-post', $why);
        $this->assertStringContainsString('/guest-post-prices-europe', $why);
        $this->assertStringContainsString('/for-agencies', $why);
        $this->assertStringNotContainsString('international reach.', $why);
    }

    public function test_english_country_titles_are_english_only(): void
    {
        $france = $this->get('/guest-posts-france')->assertOk()->getContent();
        $this->assertStringContainsString('Guest Posts in France — Verified Publishers, EUR', $france);
        $this->assertStringNotContainsString('Acheter Guest Post', $france);

        $nl = $this->get('/guest-posts-netherlands')->assertOk()->getContent();
        $this->assertStringContainsString('Guest Posts in the Netherlands — Verified Publishers, EUR', $nl);
        $this->assertStringNotContainsString('Guest Post Kopen', $nl);
    }

    public function test_homepage_links_underlinked_money_pages(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        foreach ([
            '/for-agencies',
            '/sponsored-articles',
            '/alternatives',
            '/guest-posts-germany',
            '/guest-posts-poland',
            '/guest-posts-denmark',
            '/guest-posts-sweden',
            '/guest-posts-norway',
        ] as $path) {
            $this->assertStringContainsString($path, $html, $path);
        }
    }

    public function test_eu_price_index_shows_as_of_and_csv(): void
    {
        $html = $this->get('/guest-post-prices-europe')->assertOk()->getContent();
        $this->assertStringContainsString('data-price-index-as-of', $html);
        $this->assertStringContainsString('Data as of', $html);
        $this->assertStringContainsString('/guest-post-prices-europe.csv', $html);
        $this->assertStringContainsString('Download CSV', $html);

        $csv = $this->get('/guest-post-prices-europe.csv')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $csvBody = $csv->streamedContent();
        $this->assertStringContainsString('country_code', $csvBody);
        $this->assertStringContainsString('median_eur', $csvBody);

        $this->get('/de/guest-post-prices-europe.csv')->assertRedirect('/guest-post-prices-europe.csv');
    }

    public function test_public_crawler_sees_eur_while_geo_guest_can_see_usd(): void
    {
        $request = Request::create('/pricing', 'GET');
        $request->headers->set('CF-IPCountry', 'US');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        $this->assertTrue(app(ViewerCountry::class)->isPublicCrawler($request));
        $this->assertSame('EUR', app(ViewerCountry::class)->displayCurrency($request));

        $guest = Request::create('/pricing', 'GET');
        $guest->headers->set('CF-IPCountry', 'US');
        $guest->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)');
        $this->assertFalse(app(ViewerCountry::class)->isPublicCrawler($guest));
        $this->assertSame('USD', app(ViewerCountry::class)->displayCurrency($guest));
    }

    public function test_safe_page_title_is_trimmed(): void
    {
        $html = $this->get('/is-seolinkbuildings-safe')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Is SEOLinkBuildings safe? Verified publishers, held payments</title>', $html);
        $this->assertStringNotContainsString('honest answers</title>', $html);
    }

    private static function getRouteCount(string $uri): int
    {
        $uri = trim($uri, '/');
        $count = 0;
        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() !== $uri) {
                continue;
            }
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $count++;
        }

        return $count;
    }
}
