<?php

namespace Tests\Unit;

use App\Services\ContentModeration\ContentModerationEngine;
use App\Services\ContentUpload\ArticleLanguageGuard;
use App\Services\ContentUpload\ArticlePreviewHtml;
use PHPUnit\Framework\TestCase;

class ContentLibraryModerationUxTest extends TestCase
{
    public function test_preview_html_normalizes_storage_paths(): void
    {
        $html = '<p>Hi</p><p><img src="/storage/content-articles/1/a.png" alt=""></p>';
        $out = ArticlePreviewHtml::normalizeSrc('/storage/content-articles/1/a.png', 'https://example.test', 'https://example.test/storage');
        $this->assertSame('https://example.test/storage/content-articles/1/a.png', $out);

        $normalized = ArticlePreviewHtml::normalize($html);
        $this->assertStringContainsString('content-articles/1/a.png', $normalized);
        $this->assertStringContainsString('<img', $normalized);
    }

    public function test_highlight_terms_wraps_matches_outside_tags(): void
    {
        $html = '<p>Visit our casino tonight</p><p><a href="/x">casino</a></p>';
        $out = ArticlePreviewHtml::highlightTerms($html, ['casino']);
        $this->assertStringContainsString('<mark class="slb-mod-hit">casino</mark>', $out);
        $this->assertStringContainsString('<a href="/x">', $out);
    }

    public function test_gambling_engine_matches_german_keywords(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        $result = $engine->score(
            title: 'Sportnachrichten',
            text: 'Die besten Sportwetten und Online Casino Tipps für Deutschland mit Wettanbieter Vergleich und Poker Turniere.',
            links: [],
            categories: $categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertNotEmpty($result['matched_terms']);
        $this->assertGreaterThanOrEqual(60, $result['max_confidence']);
    }

    public function test_adult_category_is_disabled_by_default_in_config_file(): void
    {
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $this->assertFalse((bool) ($cfg['categories']['adult']['enabled'] ?? true));
        $this->assertTrue((bool) ($cfg['categories']['gambling']['enabled'] ?? false));
        $this->assertArrayHasKey('de', $cfg['categories']['gambling']['keywords_by_locale']);
        $this->assertArrayHasKey('sk', $cfg['categories']['gambling']['keywords_by_locale']);
    }

    public function test_language_guard_rejects_slovak_under_german_selection(): void
    {
        $guard = new ArticleLanguageGuard;
        $slovak = str_repeat('Toto je slovenský článok o marketingu a SEO pre firmy ktoré chcú rásť. ', 12)
            .'Je dôležité, že text je napísaný po slovensky a používa bežné slová ako ktorý ktorá ktoré sú bola bolo.';

        $result = $guard->assertMatches($slovak, 'de');
        $this->assertFalse($result['ok']);
        $this->assertSame('sk', $result['detected']);
        $this->assertStringContainsString('DE', $result['message'] ?? '');
    }

    public function test_language_guard_accepts_english_when_english_selected(): void
    {
        $guard = new ArticleLanguageGuard;
        $english = str_repeat('This article explains digital marketing strategies that help brands grow organic traffic with useful content. ', 10)
            .'Readers will find clear tips about SEO, content, and conversion which are useful for their business.';

        $result = $guard->assertMatches($english, 'en');
        $this->assertTrue($result['ok']);
        $this->assertSame('en', $result['detected']);
    }
}
