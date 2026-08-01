<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketing\CatalogTeaserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageCatalogPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        $country = $overrides['country'] ?? 'de';
        $language = $overrides['language'] ?? 'en';

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Example Site',
            'site_url' => 'https://example-'.$country.'.com',
            'domain' => 'example-'.$country.'.com',
            'da' => 40,
            'dr' => 50,
            'traffic' => 1000,
            'country' => $country,
            'language' => $language,
            'countries' => [$country],
            'languages' => [$language],
            'category' => 'Marketing, PR & Advertising',
            'price' => 100,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Catalog preview inventory',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_homepage_hero_uses_static_german_catalog_image(): void
    {
        $publisher = $this->publisher();
        $this->makeSite($publisher, [
            'site_name' => 'German News Hub',
            'domain' => 'german-news-hub.de',
            'site_url' => 'https://german-news-hub.de',
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'dr' => 70,
            'da' => 60,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'French Lifestyle',
            'domain' => 'french-lifestyle.fr',
            'site_url' => 'https://french-lifestyle.fr',
            'country' => 'fr',
            'language' => 'fr',
            'countries' => ['fr'],
            'languages' => ['fr'],
            'dr' => 65,
            'da' => 55,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('dashboard.png', false)
            ->assertSee('dashboard.webp', false)
            ->assertDontSee('Live publisher catalog', false)
            ->assertDontSee('German News Hub', false)
            ->assertDontSee('advertiser/catalog', false);
    }

    public function test_homepage_always_shows_static_catalog_image_even_without_sites(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('dashboard.png', false)
            ->assertDontSee('Live publisher catalog', false)
            ->assertDontSee('advertiser/catalog', false);
    }

    public function test_hero_catalog_image_uses_contain_so_metrics_are_not_cropped(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/\.slb-hero-product\s*\{[^}]*object-fit:\s*contain/s',
            $html
        );
        $this->assertStringContainsString('aspect-ratio: 1200 / 518', $html);
        // Desktop used to force cover on .slb-hero-product (cropping metrics).
        $this->assertDoesNotMatchRegularExpression(
            '/\.slb-hero-product\s*\{[^}]*object-fit:\s*cover/s',
            $html
        );
    }

    public function test_teaser_service_diversifies_countries_before_filling(): void
    {
        $publisher = $this->publisher();

        $this->makeSite($publisher, [
            'site_name' => 'DE High',
            'domain' => 'de-high.de',
            'country' => 'de',
            'countries' => ['de'],
            'dr' => 90,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'DE Mid',
            'domain' => 'de-mid.de',
            'country' => 'de',
            'countries' => ['de'],
            'dr' => 80,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'US Site',
            'domain' => 'us-site.com',
            'country' => 'us',
            'countries' => ['us'],
            'dr' => 40,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'IT Site',
            'domain' => 'it-site.it',
            'country' => 'it',
            'countries' => ['it'],
            'dr' => 35,
        ]);

        $teasers = app(CatalogTeaserService::class)->teasers(3);

        $this->assertCount(3, $teasers);
        $countries = $teasers->pluck('country')->map(fn ($c) => strtolower((string) $c))->all();
        $this->assertContains('de', $countries);
        $this->assertContains('us', $countries);
        $this->assertContains('it', $countries);
        $this->assertSame(3, count(array_unique($countries)));
        $this->assertStringContainsString('*', (string) $teasers->first()['domain_masked']);
    }
}
