<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoLeftoverClassHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_boot_and_save_paths_guard_missing_seo_stack_classes(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('CanonicalHost.php', $bootstrap);
        $this->assertStringContainsString('is_file($canonicalHostFile)', $bootstrap);
        $this->assertStringContainsString('prependToGroup(\'web\', CanonicalHost::class)', $bootstrap);

        $web = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString('class_exists(CountryLander::class)', $web);
        $this->assertStringContainsString('class_exists(CatalogTeaserService::class)', $web);
        $this->assertStringContainsString('class_exists(LocalizedPublicPath::class)', $web);

        $controller = (string) file_get_contents(base_path('app/Http/Controllers/MarketingPageController.php'));
        $this->assertStringContainsString('class_exists(CountryLander::class)', $controller);
        $this->assertStringContainsString('class_exists(GuestPostPriceIndex::class)', $controller);
        $this->assertStringContainsString('class_exists(CatalogTeaserService::class)', $controller);
        $this->assertStringContainsString('catalogTeaserService()', $controller);
        $this->assertStringNotContainsString('$teasers->teasersForCountries', $controller);

        $sitemap = (string) file_get_contents(base_path('app/Http/Controllers/SitemapController.php'));
        $this->assertStringContainsString('class_exists(CountryLander::class)', $sitemap);
        $this->assertStringContainsString('class_exists(GuestPostPriceIndex::class)', $sitemap);

        $site = (string) file_get_contents(base_path('app/Models/Site.php'));
        $this->assertStringContainsString('class_exists(GuestPostPriceIndex::class)', $site);

        $i18n = (string) file_get_contents(base_path('app/Support/PublicI18n.php'));
        $this->assertStringContainsString('class_exists(LocalizedPublicPath::class)', $i18n);

        $home = (string) file_get_contents(base_path('resources/views/home.blade.php'));
        $about = (string) file_get_contents(base_path('resources/views/pages/about.blade.php'));
        $prices = (string) file_get_contents(base_path('resources/views/pages/guest-post-prices-europe.blade.php'));
        $this->assertStringContainsString('class_exists(\\App\\Support\\BrandOrganization::class)', $home);
        $this->assertStringContainsString('class_exists(\\App\\Support\\BrandOrganization::class)', $about);
        $this->assertStringContainsString('class_exists(\\App\\Support\\BrandOrganization::class)', $prices);
    }

    public function test_public_money_pages_and_admin_login_stay_up(): void
    {
        $this->get('/')->assertOk();
        $this->get('/about')->assertOk();
        $this->get('/marketplace')->assertOk();
        $this->get('/guest-posts-germany')->assertOk();
        $this->get('/guest-post-prices-europe')->assertOk();
        $this->get('/sitemap-en.xml')->assertOk();
        $this->get('/login')->assertOk();

        $this->assertNull(Site::forgetMarketingCaches());
    }
}
