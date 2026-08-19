<?php

namespace Tests\Unit;

use App\Models\ContentSubmission;
use App\Models\Site;
use PHPUnit\Framework\TestCase;

class AnyLanguageSiteMatchTest extends TestCase
{
    private function site(string $language): Site
    {
        $site = new Site;
        $site->language = $language;
        $site->languages = [$language];
        $site->country = $language;
        $site->countries = [$language];

        return $site;
    }

    private function article(string $language): ContentSubmission
    {
        $article = new ContentSubmission;
        $article->language = $language;

        return $article;
    }

    public function test_soft_mode_allows_any_article_language(): void
    {
        $this->assertTrue($this->article('en')->matchesSite($this->site('de'), false));
        $this->assertTrue($this->article('de')->matchesSite($this->site('nl'), false));
        $this->assertTrue($this->article('nl')->matchesSite($this->site('fr'), false));
        $this->assertTrue($this->article('sk')->matchesSite($this->site('en'), false));
    }

    public function test_hard_mode_requires_matching_language(): void
    {
        $this->assertFalse($this->article('en')->matchesSite($this->site('de'), true));
        $this->assertTrue($this->article('de')->matchesSite($this->site('de'), true));
        $this->assertTrue($this->article('nl')->languageFitsSite($this->site('nl')));
        $this->assertFalse($this->article('nl')->languageFitsSite($this->site('de')));
    }

    public function test_language_fits_helper_checks_site_languages(): void
    {
        $this->assertFalse(ContentSubmission::languageFitsSiteLanguages('nl', ['de']));
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('de', ['de', 'fr']));
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('en', []));
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('en-US', ['en']));
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('en', ['en-us']));
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('en_GB', ['en']));
        $this->assertFalse(ContentSubmission::languageFitsSiteLanguages('en-US', ['de']));
        $this->assertSame('en', ContentSubmission::languagePrimaryTag('en-US'));
        $this->assertSame('en', ContentSubmission::languagePrimaryTag('en_GB'));
        $this->assertSame('Site DE · article NL', ContentSubmission::languageMismatchLabel('nl', ['de']));
        $this->assertNull(ContentSubmission::languageMismatchLabel('de', ['de']));
        $this->assertNull(ContentSubmission::languageMismatchLabel('en-US', ['en']));
    }
}
