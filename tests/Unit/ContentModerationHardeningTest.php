<?php

namespace Tests\Unit;

use App\Services\ContentModeration\ContentModerationEngine;
use PHPUnit\Framework\TestCase;

class ContentModerationHardeningTest extends TestCase
{
    private ContentModerationEngine $engine;

    /** @var array<string, mixed> */
    private array $categories;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $this->categories = $cfg['categories'];
    }

    public function test_single_prohibited_keyword_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Entertainment notes',
            text: 'This article mentions casino once in passing about entertainment venues.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertContains('casino', $result['matched_terms']);
    }

    public function test_adult_keyword_porn_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Health guide',
            text: 'This guide discusses porn addiction recovery for families.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('adult', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_xxx_keyword_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Safety',
            text: 'Parents should filter xxx content on school devices.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('adult', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_restricted_domain_mentioned_in_text_without_href_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Odds tips',
            text: 'Check bet365 for the latest match odds before kickoff.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertNotEmpty($result['blocked_urls']);
    }

    public function test_www_domain_without_protocol_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'News',
            text: 'Visit www.pokerstars.com for tournament news and schedule updates.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertNotEmpty($result['blocked_urls']);
    }

    public function test_obfuscated_domain_with_spaced_dots_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Promo',
            text: 'See stake . com for games and bonuses this week.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_obfuscated_domain_with_dot_token_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Promo',
            text: 'Join stake[dot]com today for exclusive offers.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_cloaked_href_still_rejected(): void
    {
        $result = $this->engine->score(
            title: 'SEO tips',
            text: 'This article shares helpful SEO strategies for growing organic traffic.',
            links: ['https://www.bet365.com/en/sports'],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertNotEmpty($result['blocked_urls']);
    }

    public function test_clean_marketing_article_still_passes(): void
    {
        $result = $this->engine->score(
            title: 'Digital marketing guide',
            text: str_repeat(
                'This article explains digital marketing strategies that help brands grow organic traffic with useful content. ',
                8
            ),
            links: ['https://example.com/guide'],
            categories: $this->categories,
        );

        $this->assertLessThan(70, $result['max_confidence']);
        $this->assertEmpty($result['blocked_urls']);
    }

    public function test_crypto_promo_copy_is_accepted(): void
    {
        $result = $this->engine->score(
            title: 'Bitcoin market notes',
            text: 'Guaranteed crypto profits from our pump and dump group — get rich with bitcoin now.',
            links: [],
            categories: $this->categories,
        );

        $this->assertLessThan(70, $result['max_confidence']);
        $this->assertNotSame('crypto_promo', $result['detected_category']);
    }

    public function test_generic_english_words_are_not_treated_as_gambling(): void
    {
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $samples = [
            'What are the odds of this campaign succeeding in the next quarter?',
            'Teams can stave off burnout with better planning and rest.',
            'Identify every workplace hazard before the warehouse site audit.',
            'Book the remaining time slots for next week interviews.',
        ];

        foreach ($samples as $text) {
            $result = $this->engine->score(
                title: 'Digital marketing guide',
                text: $text,
                links: [],
                categories: $this->categories,
                exceptions: $cfg['exceptions'] ?? [],
            );

            $this->assertLessThan(70, $result['max_confidence'], $text);
            $this->assertNotContains('odds', $result['matched_terms']);
            $this->assertNotContains('stave', $result['matched_terms']);
            $this->assertNotContains('hazard', $result['matched_terms']);
        }
    }

    public function test_locale_specific_odds_and_stake_phrases_still_fail(): void
    {
        $samples = [
            'spelodds',
            'spilleodds',
            'športne stave',
            'hazardní hry',
            'gry hazardowe',
        ];

        foreach ($samples as $term) {
            $result = $this->engine->score(
                title: 'Industry notes',
                text: 'This marketing briefing mentions '.$term.' among other venue types.',
                links: [],
                categories: $this->categories,
            );

            $this->assertSame('gambling', $result['detected_category'], $term.' should fail gambling');
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $term);
        }
    }

    public function test_casino_royale_exception_does_not_false_positive_alone(): void
    {
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $result = $this->engine->score(
            title: 'Movie night',
            text: 'We watched Casino Royale and talked about the soundtrack and cinematography.',
            links: [],
            categories: $this->categories,
            exceptions: $cfg['exceptions'] ?? [],
        );

        $this->assertLessThan(70, $result['max_confidence']);
    }

    public function test_score_still_finds_a_keyword_near_the_start_of_a_long_article(): void
    {
        $text = 'This article mentions casino once in passing about entertainment venues. '
            .str_repeat('Useful editorial content about productivity software for busy teams. ', 8000);

        $result = $this->engine->score(
            title: 'Entertainment notes',
            text: $text,
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertContains('casino', $result['matched_terms']);
    }

    public function test_extract_urls_from_text_finds_www_and_https(): void
    {
        $urls = $this->engine->extractUrlsFromText(
            'Read https://example.com/a and also www.pokerstars.com/play for details.'
        );

        $joined = implode(' ', $urls);
        $this->assertStringContainsString('example.com', $joined);
        $this->assertStringContainsString('pokerstars.com', $joined);
    }

    public function test_every_marketplace_language_has_gambling_and_adult_keywords(): void
    {
        $markets = require dirname(__DIR__, 2).'/config/markets.php';
        $languages = $markets['allowed_language_codes'] ?? [];
        $this->assertNotEmpty($languages);

        $gamblingLocales = $this->categories['gambling']['keywords_by_locale'] ?? [];
        $adultLocales = $this->categories['adult']['keywords_by_locale'] ?? [];

        foreach ($languages as $code) {
            if ($code === 'en') {
                continue;
            }
            $this->assertArrayHasKey($code, $gamblingLocales, 'gambling missing locale '.$code);
            $this->assertNotEmpty($gamblingLocales[$code], 'gambling empty locale '.$code);
            $this->assertArrayHasKey($code, $adultLocales, 'adult missing locale '.$code);
            $this->assertNotEmpty($adultLocales[$code], 'adult empty locale '.$code);
        }

        foreach (['cbd', 'alcohol', 'tobacco', 'weapons'] as $category) {
            $locales = $this->categories[$category]['keywords_by_locale'] ?? [];
            foreach ($languages as $code) {
                if ($code === 'en') {
                    continue;
                }
                $this->assertArrayHasKey($code, $locales, $category.' missing locale '.$code);
                $this->assertNotEmpty($locales[$code], $category.' empty locale '.$code);
            }
        }
    }

    public function test_foreign_gambling_terms_fail_even_when_the_article_is_not_in_that_language(): void
    {
        $samples = [
            'gambling' => [
                'paris sportifs',
                'glücksspiel',
                'pengespill',
                'apostes esportives',
                'كازينو',
                '赌场',
                'sportweddenschappen',
                'zakłady bukmacherskie',
            ],
            'adult' => [
                'pornografie',
                'pornografía',
                '色情',
                'إباحية',
            ],
        ];

        foreach ($samples['gambling'] as $term) {
            $result = $this->engine->score(
                title: 'Industry notes',
                text: 'This marketing briefing mentions '.$term.' among other venue types.',
                links: [],
                categories: $this->categories,
            );
            $this->assertSame('gambling', $result['detected_category'], $term.' should fail gambling');
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $term);
        }

        foreach ($samples['adult'] as $term) {
            $result = $this->engine->score(
                title: 'Safety notes',
                text: 'Parents should filter '.$term.' on shared devices at home.',
                links: [],
                categories: $this->categories,
            );
            $this->assertSame('adult', $result['detected_category'], $term.' should fail adult');
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $term);
        }
    }

    public function test_merged_keywords_include_every_locale_list(): void
    {
        $merged = $this->engine->mergedKeywords($this->categories['gambling']);
        $this->assertContains('casino', $merged);
        $this->assertContains('glücksspiel', $merged);
        $this->assertContains('paris sportifs', $merged);
        $this->assertContains('pengespill', $merged);
        $this->assertContains('كازينو', $merged);

        $adult = $this->engine->mergedKeywords($this->categories['adult']);
        $this->assertContains('pornography', $adult);
        $this->assertContains('pornografie', $adult);
        $this->assertContains('色情', $adult);
    }
}
