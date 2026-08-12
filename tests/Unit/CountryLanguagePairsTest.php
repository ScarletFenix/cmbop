<?php

namespace Tests\Unit;

use App\Services\Marketplace\CountryLanguagePairs;
use Tests\TestCase;

class CountryLanguagePairsTest extends TestCase
{
    public function test_germany_allows_german_only(): void
    {
        $pairs = app(CountryLanguagePairs::class);

        $this->assertSame(['de'], $pairs->languageCodesForCountry('de'));
        $this->assertTrue($pairs->isAllowedPair('de', 'de'));
        $this->assertFalse($pairs->isAllowedPair('de', 'en'));
    }

    public function test_gulf_allows_arabic_and_english(): void
    {
        $pairs = app(CountryLanguagePairs::class);

        $this->assertEqualsCanonicalizing(['ar', 'en'], $pairs->languageCodesForCountry('ae'));
        $this->assertTrue($pairs->isAllowedPair('ae', 'en'));
        $this->assertTrue($pairs->isAllowedPair('ae', 'ar'));
        $this->assertFalse($pairs->isAllowedPair('ae', 'de'));
    }

    public function test_language_codes_for_countries_unions_pairs(): void
    {
        $pairs = app(CountryLanguagePairs::class);

        $codes = $pairs->languageCodesForCountries(['de', 'ae']);
        $this->assertContains('de', $codes);
        $this->assertContains('ar', $codes);
        $this->assertContains('en', $codes);
    }
}
