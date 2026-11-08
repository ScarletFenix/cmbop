<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\CountryLander;
use App\Support\LocaleIndexability;
use App\Support\PublicI18n;
use App\Support\RobotsTxt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SeoTechnicalFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_locale_money_x_default_is_self_reciprocal(): void
    {
        $this->assertSame('fr', PublicI18n::moneyLanderXDefault('acheter-backlinks'));
        $this->assertSame(['fr' => 'acheter-backlinks'], PublicI18n::moneyLanderPathByLocale('acheter-backlinks'));

        $html = $this->get('/fr/acheter-backlinks')->assertOk()->getContent();
        $this->assertStringContainsString('hreflang="fr" href="'.url('/fr/acheter-backlinks').'"', $html);
        $this->assertStringContainsString('hreflang="x-default" href="'.url('/fr/acheter-backlinks').'"', $html);
        $this->assertStringNotContainsString('hreflang="x-default" href="'.url('/guest-posts-france').'"', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
    }

    public function test_shared_money_cluster_keeps_in_cluster_x_default(): void
    {
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('digital-pr'));
        $this->assertSame('de', PublicI18n::moneyLanderXDefault('gastbeitrag-kaufen'));

        $html = $this->get('/fr/digital-pr')->assertOk()->getContent();
        $this->assertStringContainsString('hreflang="it" href="'.url('/it/digital-pr').'"', $html);
        $this->assertStringContainsString('hreflang="x-default" href="'.url('/it/digital-pr').'"', $html);
    }

    public function test_language_switcher_skips_thin_locale_fallbacks(): void
    {
        $about = Request::create('/about', 'GET');
        $this->assertFalse(LocaleIndexability::isIndexable('us', 'about'));
        $this->assertFalse(LocaleIndexability::isIndexable('pt', 'about'));
        $this->assertFalse(LocaleIndexability::isIndexable('at', 'about'));
        $this->assertTrue(LocaleIndexability::isIndexable('de', 'about'));
        $this->assertTrue(LocaleIndexability::isIndexable('fr', 'about'));

        $this->assertSame(
            PublicI18n::withExplicitLocaleQuery(url('/about'), 'en'),
            PublicI18n::switchUrl($about, 'us')
        );
        $this->assertSame(
            PublicI18n::withExplicitLocaleQuery(url('/about'), 'en'),
            PublicI18n::switchUrl($about, 'pt')
        );
        $this->assertSame(url('/de/ueber-uns'), PublicI18n::switchUrl($about, 'at'));
        $this->assertSame(url('/fr/a-propos'), PublicI18n::switchUrl($about, 'fr'));

        $home = Request::create('/', 'GET');
        $this->assertSame(url('/at'), PublicI18n::switchUrl($home, 'at'));
        $this->assertSame(url('/pt'), PublicI18n::switchUrl($home, 'pt'));
        $this->assertSame(
            PublicI18n::withExplicitLocaleQuery(url('/'), 'en'),
            PublicI18n::switchUrl($home, 'us')
        );
    }

    public function test_french_blog_chrome_is_localized(): void
    {
        $html = $this->get('/fr/blog')->assertOk()->getContent();
        $this->assertStringContainsString('Blog et articles', $html);
        $this->assertStringContainsString('Articles récents', $html);
        $this->assertStringNotContainsString('>Blog & Articles<', $html);
        $this->assertStringNotContainsString('>Recent Articles<', $html);
        $this->assertStringNotContainsString('>All Posts<', $html);
    }

    public function test_oauth_html_is_noindex_and_robots_txt_does_not_disallow_it(): void
    {
        $authorize = file_get_contents(resource_path('views/mcp/oauth/authorize.blade.php'));
        $denied = file_get_contents(resource_path('views/mcp/oauth/denied.blade.php'));
        $this->assertStringContainsString("@section('robots', 'noindex, nofollow')", $authorize);
        $this->assertStringContainsString("@section('skip_hreflang', '1')", $authorize);
        $this->assertStringContainsString("@section('robots', 'noindex, nofollow')", $denied);

        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->get('/oauth/authorize')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee('hreflang="x-default"', false);

        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringNotContainsString('Disallow: /oauth', $robots);
        $this->assertStringContainsString('Disallow: /admin/', $robots);
        $this->assertSame(RobotsTxt::disallows(), array_values(array_filter(
            RobotsTxt::disallows(),
            static fn (string $rule) => ! str_starts_with($rule, '/oauth')
        )));
    }

    public function test_admin_confirm_views_declare_noindex_without_changing_approval_copy(): void
    {
        $deposit = file_get_contents(resource_path('views/admin/deposits/approve-confirm.blade.php'));
        $payout = file_get_contents(resource_path('views/admin/withdrawals/mark-paid-confirm.blade.php'));
        $this->assertStringContainsString("@section('robots', 'noindex, nofollow')", $deposit);
        $this->assertStringContainsString("@section('skip_hreflang', '1')", $deposit);
        $this->assertStringContainsString("@section('robots', 'noindex, nofollow')", $payout);
        $this->assertStringContainsString('Confirm deposit approval', $deposit);
        $this->assertStringContainsString('Confirm payout marked paid', $payout);
    }

    public function test_public_layout_drops_google_fonts_and_serves_logo_webp(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
        $this->assertStringContainsString('logo1-hero.webp', $html);
        $this->assertStringContainsString('logo1.png', $html);
        $this->assertStringContainsString('guest-post-prices-europe', $html);
        foreach (CountryLander::slugs() as $slug) {
            $this->assertStringContainsString('/'.$slug, $html, $slug);
        }
    }

    public function test_swiss_and_spanish_home_clusters_are_not_duplicated(): void
    {
        $home = file_get_contents(resource_path('views/home.blade.php'));
        $this->assertSame(1, substr_count($home, "SwissMoneyLanders::clusterLinks('home')"));
        $this->assertSame(1, substr_count($home, "SpanishMoneyLanders::clusterLinks('home')"));

        $pricing = file_get_contents(resource_path('views/pages/pricing.blade.php'));
        $this->assertSame(1, substr_count($pricing, "SwissMoneyLanders::clusterLinks('preise')"));
        $this->assertSame(1, substr_count($pricing, "SpanishMoneyLanders::clusterLinks('precios')"));
    }

    public function test_llms_txt_does_not_list_thin_us_or_fallback_urls(): void
    {
        $body = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('/us', $body);
        $this->assertStringContainsString('noindex, follow', $body);
        $this->assertStringNotContainsString('seolinkbuildings.com/us/about', $body);
        $this->assertStringNotContainsString('/pt/sobre-nos', $body);
        $this->assertStringNotContainsString('/at/ueber-uns', $body);
        $this->assertStringContainsString('/pt/comprar-guest-post', $body);
        $this->assertStringContainsString('/at/gastbeitrag-kaufen', $body);
    }

    public function test_legacy_terms_of_service_alias_redirects(): void
    {
        $this->get('/terms-of-service')->assertRedirect('/terms-of-services');
        $this->assertSame(301, $this->get('/terms-of-service')->status());
    }
}
