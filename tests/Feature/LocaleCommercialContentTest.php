<?php

namespace Tests\Feature;

use App\Services\CuratedBlogWriter;
use App\Support\DanishMoneyLanders;
use App\Support\DofollowNofollowAnchorsEnBlogPost;
use App\Support\FrenchMoneyLanders;
use App\Support\GermanMoneyLanders;
use App\Support\LinkBuildingGuideBlogPost;
use App\Support\PublicI18n;
use Database\Seeders\LinkBuildingGuidesBlogsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleCommercialContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_german_guest_post_page_adds_gastartikel_fit_copy_and_strategy_links(): void
    {
        $page = GermanMoneyLanders::find('gastbeitrag-kaufen');
        $this->assertNotNull($page);
        $this->assertSame('Gastbeiträge kaufen – relevante Publisher für Ihre SEO', $page['h1']);
        $this->assertSame('Gastbeitrag kaufen in Deutschland | SEOLinkBuildings', $page['meta_title']);
        $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']));

        $html = $this->get('/de/gastbeitrag-kaufen')->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString('Gastartikel kaufen: dieselbe Bestellung', $html);
        $this->assertStringContainsString('Passung prüfen, bevor Sie zahlen', $html);
        $this->assertStringContainsString('rel="sponsored"', $html);
        $this->assertStringContainsString('umgehen Googles Richtlinien nicht', $html);
        $this->assertStringContainsString('href="/de/blog/linkaufbau-strategien"', $html);
        $this->assertStringContainsString('href="/de/blog/dofollow-vs-nofollow-ankertext"', $html);
        $this->assertStringContainsString('href="/register"', $html);
        $this->assertStringContainsString('href="/de/so-funktioniert-es"', $html);
        $this->assertStringContainsString('rel="canonical" href="'.url('/de/gastbeitrag-kaufen').'"', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
        $this->assertStringNotContainsString('/uk', $html);
        $this->assertStringNotContainsString('advertiser/catalog', $html);

        $this->get('/de/gastartikel-kaufen')->assertRedirect('/de/gastbeitrag-kaufen');

        CuratedBlogWriter::upsert(
            DofollowNofollowAnchorsEnBlogPost::SLUG,
            DofollowNofollowAnchorsEnBlogPost::payload()
        );
        $this->get('/de/blog/dofollow-vs-nofollow-ankertext')->assertOk();
    }

    public function test_danish_backlinks_page_explains_fit_editorial_terms_and_guides(): void
    {
        $page = DanishMoneyLanders::find('koeb-backlinks');
        $this->assertNotNull($page);
        $this->assertSame('Køb backlinks i Danmark', $page['h1']);
        $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']));
        $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']));
        $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']));

        $html = $this->get('/dk/koeb-backlinks')->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString('Sådan tjekker du, om sitet passer', $html);
        $this->assertStringContainsString('Redaktionelle vilkår og link-attribut', $html);
        $this->assertStringContainsString('rel="sponsored"', $html);
        $this->assertStringContainsString('/dk/vejledning', $html);
        $this->assertStringContainsString('href="/blog/link-building-guide"', $html);
        $this->assertStringContainsString('/dk/koeb-gaesteindlaeg', $html);
        $this->assertStringContainsString('href="/register"', $html);
        $this->assertStringContainsString('rel="canonical" href="'.url('/dk/koeb-backlinks').'"', $html);
        $this->assertStringContainsString('hreflang="da-DK"', $html);
        $this->assertStringContainsString('hreflang="x-default" href="'.url('/dk/koeb-backlinks').'"', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
        $this->assertStringNotContainsString('/uk', $html);
        $this->assertStringNotContainsString('advertiser/catalog', $html);
        $this->assertStringNotContainsString('garantér ranking', $html);
    }

    public function test_french_backlinks_page_explains_french_fit_and_links_local_guide(): void
    {
        $page = FrenchMoneyLanders::find('acheter-backlinks');
        $this->assertNotNull($page);
        $this->assertSame('Acheter des backlinks en France', $page['h1']);
        $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']));
        $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']));
        $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']));

        $html = $this->get('/fr/acheter-backlinks')->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString('backlinks français', $html);
        $this->assertStringContainsString('Choisir un site français et vérifier l’adéquation', $html);
        $this->assertStringContainsString('Conditions éditoriales et attributs de lien', $html);
        $this->assertStringContainsString('rel="sponsored"', $html);
        $this->assertStringContainsString('href="/fr/blog/guide-netlinking"', $html);
        $this->assertStringContainsString('href="/fr/acheter-guest-post"', $html);
        $this->assertStringContainsString('href="/fr/comment-ca-marche"', $html);
        $this->assertStringContainsString('rel="canonical" href="'.url('/fr/acheter-backlinks').'"', $html);
        $this->assertStringContainsString('hreflang="fr" href="'.url('/fr/acheter-backlinks').'"', $html);
        $this->assertStringContainsString('hreflang="x-default" href="'.url('/fr/acheter-backlinks').'"', $html);
        $this->assertStringNotContainsString('hreflang="en-GB"', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
        $this->assertStringNotContainsString('/uk', $html);
        $this->assertStringNotContainsString('advertiser/catalog', $html);
        $this->assertSame('fr', PublicI18n::moneyLanderXDefault('acheter-backlinks'));
    }

    public function test_link_building_guides_link_existing_locale_commercial_pages(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);

        $en = $this->get('/blog/link-building-guide')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('/<h1\b/i', $en));
        $this->assertStringContainsString('href="/de/gastbeitrag-kaufen"', $en);
        $this->assertStringContainsString('href="/fr/acheter-backlinks"', $en);
        $this->assertStringContainsString('href="/dk/koeb-backlinks"', $en);
        $this->assertStringContainsString('href="/marketplace"', $en);
        $this->assertStringContainsString('rel="canonical" href="'.PublicI18n::urlForLocale('blog/'.LinkBuildingGuideBlogPost::SLUG, 'en').'"', $en);
        $this->assertStringNotContainsString('name="robots" content="noindex', $en);
        $this->assertStringNotContainsString('/uk', $en);

        $de = $this->get('/de/blog/linkaufbau-strategien')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('/<h1\b/i', $de));
        $this->assertStringContainsString('Gastbeitrag kaufen oder selbst Outreach?', $de);
        $this->assertStringContainsString('href="/de/gastbeitrag-kaufen"', $de);
        $this->assertStringContainsString('Marktplatz auf Deutsch', $de);
        $this->assertMatchesRegularExpression('#href="/de"(?:\s|>)#', $de);
        $this->assertStringContainsString('rel="canonical" href="'.PublicI18n::urlForLocale('blog/linkaufbau-strategien', 'de').'"', $de);
        $this->assertStringNotContainsString('name="robots" content="noindex', $de);

        $fr = $this->get('/fr/blog/guide-netlinking')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('/<h1\b/i', $fr));
        $this->assertStringContainsString('href="/fr/acheter-backlinks"', $fr);
        $this->assertStringContainsString('href="/fr/acheter-guest-post"', $fr);
        $this->assertStringContainsString('marketplace France', $fr);
        $this->assertMatchesRegularExpression('#href="/fr"(?:\s|>)#', $fr);
        $this->assertStringContainsString('rel="canonical" href="'.PublicI18n::urlForLocale('blog/guide-netlinking', 'fr').'"', $fr);
        $this->assertStringNotContainsString('name="robots" content="noindex', $fr);
    }

    public function test_commercial_internal_targets_are_live_indexable_routes(): void
    {
        foreach ([
            '/de/gastbeitrag-kaufen',
            '/de/so-funktioniert-es',
            '/de/marktplatz',
            '/dk/koeb-backlinks',
            '/dk/koeb-gaesteindlaeg',
            '/dk/vejledning',
            '/fr/acheter-backlinks',
            '/fr/acheter-guest-post',
            '/fr/guide',
            '/fr/comment-ca-marche',
            '/register',
        ] as $path) {
            $this->get($path)->assertOk();
        }

        $this->seed(LinkBuildingGuidesBlogsSeeder::class);
        $this->get('/fr/blog/guide-netlinking')->assertOk();
        $this->get('/de/blog/linkaufbau-strategien')->assertOk();
        $this->get('/blog/link-building-guide')->assertOk();
    }
}
