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
        $this->assertStringContainsString('at', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('ch', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('ro', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('en', PublicI18n::supportedPattern());
        $this->assertFalse(PublicI18n::isPrefixed('en'));
        $this->assertTrue(PublicI18n::isPrefixed('us'));
        $this->assertTrue(PublicI18n::isPrefixed('ee'));
    }

    public function test_hreflang_and_og_locale_split_uk_and_us_english(): void
    {
        $this->assertSame('en-GB', PublicI18n::hreflang('en'));
        $this->assertSame('en-US', PublicI18n::hreflang('us'));
        $this->assertSame('es', PublicI18n::hreflang('es'));
        $this->assertSame('it', PublicI18n::hreflang('it'));
        $this->assertSame('de-AT', PublicI18n::hreflang('at'));
        $this->assertSame('de-CH', PublicI18n::hreflang('ch'));
        $this->assertSame('el-GR', PublicI18n::hreflang('gr'));
        $this->assertSame('sv-SE', PublicI18n::hreflang('se'));
        $this->assertSame('et-EE', PublicI18n::hreflang('ee'));

        $this->assertSame('en_GB', PublicI18n::ogLocale('en'));
        $this->assertSame('en_US', PublicI18n::ogLocale('us'));
        $this->assertSame('es_ES', PublicI18n::ogLocale('es'));
        $this->assertSame('it_IT', PublicI18n::ogLocale('it'));
        $this->assertSame('de_AT', PublicI18n::ogLocale('at'));
        $this->assertSame('el_GR', PublicI18n::ogLocale('gr'));
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
        $this->assertSame('at', PublicI18n::fromBrowserTag('de-AT'));
        $this->assertSame('ch', PublicI18n::fromBrowserTag('de-CH'));
        $this->assertSame('gr', PublicI18n::fromBrowserTag('el-GR'));
        $this->assertSame('dk', PublicI18n::fromBrowserTag('da-DK'));
        $this->assertSame('se', PublicI18n::fromBrowserTag('sv-SE'));
        $this->assertSame('no', PublicI18n::fromBrowserTag('nb-NO'));
        $this->assertSame('ee', PublicI18n::fromBrowserTag('et-EE'));
        $this->assertSame('ro', PublicI18n::fromBrowserTag('ro-RO'));
        $this->assertNull(PublicI18n::fromBrowserTag(''));
        $this->assertNull(PublicI18n::fromBrowserTag('ja-JP'));
    }

    public function test_short_label_uses_uk_for_default_english(): void
    {
        $this->assertSame('UK', PublicI18n::shortLabel('en'));
        $this->assertSame('US', PublicI18n::shortLabel('us'));
        $this->assertSame('ES', PublicI18n::shortLabel('es'));
        $this->assertSame('AT', PublicI18n::shortLabel('at'));
        $this->assertSame('CH', PublicI18n::shortLabel('ch'));
        $this->assertSame('de', PublicI18n::messagesFallback('at'));
        $this->assertSame('de', PublicI18n::messagesFallback('ch'));
        $this->assertSame(['ro'], PublicI18n::catalogTeaserCountries('ro'));
    }
}
