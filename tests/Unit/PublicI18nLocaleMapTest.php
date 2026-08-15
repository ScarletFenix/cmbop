<?php

namespace Tests\Unit;

use App\Support\PublicI18n;
use Tests\TestCase;

class PublicI18nLocaleMapTest extends TestCase
{
    public function test_route_patterns_include_new_locales(): void
    {
        $this->assertStringContainsString('es', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('it', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('us', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('en', PublicI18n::supportedPattern());
        $this->assertFalse(PublicI18n::isPrefixed('en'));
        $this->assertTrue(PublicI18n::isPrefixed('us'));
    }

    public function test_hreflang_and_og_locale_split_uk_and_us_english(): void
    {
        $this->assertSame('en-GB', PublicI18n::hreflang('en'));
        $this->assertSame('en-US', PublicI18n::hreflang('us'));
        $this->assertSame('es', PublicI18n::hreflang('es'));
        $this->assertSame('it', PublicI18n::hreflang('it'));

        $this->assertSame('en_GB', PublicI18n::ogLocale('en'));
        $this->assertSame('en_US', PublicI18n::ogLocale('us'));
        $this->assertSame('es_ES', PublicI18n::ogLocale('es'));
        $this->assertSame('it_IT', PublicI18n::ogLocale('it'));
    }

    public function test_browser_tags_map_to_supported_locales(): void
    {
        $this->assertSame('us', PublicI18n::fromBrowserTag('en-US'));
        $this->assertSame('us', PublicI18n::fromBrowserTag('en_US'));
        $this->assertSame('en', PublicI18n::fromBrowserTag('en-GB'));
        $this->assertSame('en', PublicI18n::fromBrowserTag('en'));
        $this->assertSame('en', PublicI18n::fromBrowserTag('en-AU'));
        $this->assertSame('es', PublicI18n::fromBrowserTag('es-ES'));
        $this->assertSame('it', PublicI18n::fromBrowserTag('it-IT'));
        $this->assertSame('de', PublicI18n::fromBrowserTag('de-DE'));
        $this->assertNull(PublicI18n::fromBrowserTag(''));
        $this->assertNull(PublicI18n::fromBrowserTag('ja-JP'));
    }
}
