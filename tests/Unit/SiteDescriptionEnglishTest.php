<?php

namespace Tests\Unit;

use App\Support\SiteDescriptionRules;
use Tests\TestCase;

class SiteDescriptionEnglishTest extends TestCase
{
    public function test_german_brief_does_not_look_english(): void
    {
        $this->assertFalse(SiteDescriptionRules::looksLikeEnglish(
            'Ein deutscher Verlag für Gastbeiträge mit klarer Zielgruppe und vielen Lesern in der Region.'
        ));
    }

    public function test_english_brief_looks_english(): void
    {
        $this->assertTrue(SiteDescriptionRules::looksLikeEnglish(
            'This listing is for your audience and the publishers who write with them about guest posts.'
        ));
    }

    public function test_empty_or_short_brief_does_not_look_english(): void
    {
        $this->assertFalse(SiteDescriptionRules::looksLikeEnglish(''));
        $this->assertFalse(SiteDescriptionRules::looksLikeEnglish('<p>Kurz</p>'));
    }

    public function test_listing_language_is_not_used(): void
    {
        $this->assertTrue(SiteDescriptionRules::looksLikeEnglish(
            'This website is for advertisers and your audience when you need guest posts from publishers.'
        ));
    }
}
