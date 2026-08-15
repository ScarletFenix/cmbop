<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicI18nTest extends TestCase
{
    use RefreshDatabase;

    public function test_german_home_is_localized_with_hreflang(): void
    {
        $this->get('/de')
            ->assertOk()
            ->assertSee('lang="de"', false)
            ->assertSee('hreflang="de"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertSee('Marktplatz', false)
            ->assertSee('Registrieren', false);
    }

    public function test_locale_login_redirects_to_english_auth(): void
    {
        $this->get('/de/login')
            ->assertRedirect('/login');

        $this->get('/fr/register')
            ->assertRedirect('/register');

        $this->get('/es/login')
            ->assertRedirect('/login');

        $this->get('/us/register')
            ->assertRedirect('/register');
    }

    public function test_english_login_has_no_language_switcher(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('id="languageDropdown"', false)
            ->assertSee('Continue with Google', false);
    }

    public function test_public_marketing_pages_exist_for_each_locale(): void
    {
        $prefixes = [''];
        foreach (\App\Support\PublicI18n::prefixed() as $locale) {
            $prefixes[] = '/'.$locale;
        }

        foreach ($prefixes as $prefix) {
            foreach (['/pricing', '/marketplace', '/faq', '/about', '/cookie-policy', '/refund-policy'] as $path) {
                $this->get($prefix.$path)->assertOk();
            }
        }
    }

    public function test_locale_sitemaps_are_available(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('sitemap-de.xml', false)
            ->assertSee('sitemap-es.xml', false)
            ->assertSee('sitemap-it.xml', false)
            ->assertSee('sitemap-us.xml', false);

        $this->get('/sitemap-de.xml')
            ->assertOk()
            ->assertSee('/de/marketplace', false)
            ->assertSee('hreflang="fr"', false)
            ->assertSee('hreflang="en-GB"', false)
            ->assertSee('hreflang="en-US"', false);

        $this->get('/sitemap-us.xml')
            ->assertOk()
            ->assertSee('/us/marketplace', false);
    }

    public function test_browser_language_suggestion_banner_appears_on_english_home(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
            ->get('/')
            ->assertOk()
            ->assertSee('localeSuggestBanner', false)
            ->assertSee('Deutsch', false);
    }

    public function test_uk_english_home_uses_en_gb_tags_and_uk_label(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('lang="en-GB"', false)
            ->assertSee('hreflang="en-GB"', false)
            ->assertSee('hreflang="en-US"', false)
            ->assertSee('hreflang="es"', false)
            ->assertSee('hreflang="it"', false)
            ->assertSee('og:locale" content="en_GB"', false)
            ->assertSee('English (UK)', false)
            ->assertSee('English (US)', false)
            ->assertSee('Español', false)
            ->assertSee('Italiano', false);
    }

    public function test_us_spanish_and_italian_homes_are_routed(): void
    {
        $this->get('/us')
            ->assertOk()
            ->assertSee('lang="en-US"', false)
            ->assertSee('og:locale" content="en_US"', false)
            ->assertSee('English (US)', false);

        $this->get('/es')
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Español', false);

        $this->get('/it')
            ->assertOk()
            ->assertSee('lang="it"', false)
            ->assertSee('Italiano', false);
    }

    public function test_spanish_and_italian_homes_use_translated_copy(): void
    {
        $this->get('/es')
            ->assertOk()
            ->assertSee('Iniciar sesión', false)
            ->assertSee('Registrarse', false)
            ->assertSee('El marketplace global de link building', false)
            ->assertSee('Cómo funciona', false);

        $this->get('/it')
            ->assertOk()
            ->assertSee('Accedi', false)
            ->assertSee('Registrati', false)
            ->assertSee('Il marketplace globale di link building', false)
            ->assertSee('Come funziona', false);
    }

    public function test_us_english_browser_language_suggests_us_locale(): void
    {
        $this->withHeader('Accept-Language', 'en-US,en;q=0.8')
            ->get('/')
            ->assertOk()
            ->assertSee('localeSuggestBanner', false)
            ->assertSee('It looks like you prefer English (US)', false);
    }

    public function test_about_and_contact_titles_do_not_collide(): void
    {
        $this->get('/de/about')
            ->assertOk()
            ->assertSee('Der Guest-Post-Marktplatz für Europa', false);

        $this->get('/de/contact')
            ->assertOk()
            ->assertSee('Über SEOLinkBuildings', false)
            ->assertDontSee('Der Guest-Post-Marktplatz für Europa', false);
    }
}
