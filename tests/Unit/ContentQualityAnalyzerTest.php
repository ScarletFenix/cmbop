<?php

namespace Tests\Unit;

use App\Services\ContentModeration\ContentQualityAnalyzer;
use PHPUnit\Framework\TestCase;

class ContentQualityAnalyzerTest extends TestCase
{
    private ContentQualityAnalyzer $analyzer;

    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new ContentQualityAnalyzer;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $this->config = $cfg['quality'];
        $this->config['block_on_quality_failure'] = true;
    }

    public function test_too_many_outbound_links_are_a_blocking_failure(): void
    {
        $links = [];
        for ($i = 1; $i <= 16; $i++) {
            $links[] = 'https://example.com/page-'.$i;
        }

        $result = $this->analyzer->analyze($this->body(), '<p>'.$this->body().'</p>', $links, $this->config);

        $this->assertContains('external_links', $result['blocking_issues']);
        $this->assertSame('fail', $this->checkStatus($result, 'external_links'));
    }

    public function test_url_shortener_is_always_a_blocking_failure(): void
    {
        $result = $this->analyzer->analyze(
            $this->body(),
            '<p>Read more</p>',
            ['https://bit.ly/abc123'],
            $this->config
        );

        $this->assertContains('url_shortener', $result['blocking_issues']);
        $this->assertSame('fail', $this->checkStatus($result, 'external_links'));
        $this->assertContains('https://bit.ly/abc123', $result['shortener_urls']);
    }

    public function test_too_many_links_and_a_shortener_are_both_reported(): void
    {
        $links = ['https://bit.ly/abc123'];
        for ($i = 1; $i <= 16; $i++) {
            $links[] = 'https://example.com/page-'.$i;
        }

        $result = $this->analyzer->analyze($this->body(), '<p>'.$this->body().'</p>', $links, $this->config);

        $this->assertContains('external_links', $result['blocking_issues']);
        $this->assertContains('url_shortener', $result['blocking_issues']);
        $this->assertSame('fail', $this->checkStatus($result, 'external_links'));
        $this->assertSame('fail', $this->checkStatus($result, 'url_shortener'));
    }

    public function test_placeholder_text_is_a_blocking_failure(): void
    {
        $result = $this->analyzer->analyze(
            'Lorem ipsum dolor sit amet '.$this->body(),
            '<p>Lorem ipsum dolor sit amet</p>',
            [],
            $this->config
        );

        $this->assertContains('placeholder', $result['blocking_issues']);
    }

    public function test_short_word_count_is_not_a_hard_block(): void
    {
        $result = $this->analyzer->analyze('Short piece.', '<p>Short piece.</p>', [], $this->config);

        $this->assertSame('fail', $this->checkStatus($result, 'word_count'));
        $this->assertNotContains('word_count', $result['blocking_issues']);
    }

    public function test_clean_article_with_a_normal_link_passes_quality_gates(): void
    {
        $result = $this->analyzer->analyze(
            $this->body(),
            '<p>'.$this->body().'</p>',
            ['https://example.com/guide'],
            $this->config
        );

        $this->assertSame([], $result['blocking_issues']);
        $this->assertSame('pass', $this->checkStatus($result, 'external_links'));
        $this->assertSame('pass', $this->checkStatus($result, 'placeholder'));
    }

    public function test_is_shortener_matches_known_hosts(): void
    {
        $this->assertTrue($this->analyzer->isShortener('https://bit.ly/x', $this->config));
        $this->assertTrue($this->analyzer->isShortener('https://www.tinyurl.com/x', $this->config));
        $this->assertTrue($this->analyzer->isShortener('https://t.ly/abc', $this->config));
        $this->assertFalse($this->analyzer->isShortener('https://example.com/x', $this->config));
    }

    public function test_plain_text_shortener_is_found_without_an_href(): void
    {
        $result = $this->analyzer->analyze(
            $this->body().' Read more at bit.ly/guestpost today.',
            '<p>Read more at bit.ly/guestpost today.</p>',
            [],
            $this->config
        );

        $this->assertContains('url_shortener', $result['blocking_issues']);
        $this->assertNotEmpty($result['shortener_urls']);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function checkStatus(array $result, string $key): ?string
    {
        foreach ($result['checks'] as $check) {
            if (($check['key'] ?? '') === $key) {
                return $check['status'] ?? null;
            }
        }

        return null;
    }

    private function body(): string
    {
        return str_repeat(
            'This article explains digital marketing strategies that help brands grow organic traffic with useful content. ',
            8
        );
    }
}
