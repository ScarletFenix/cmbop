<?php

namespace Tests\Unit;

use App\Support\SiteDescriptionRules;
use PHPUnit\Framework\TestCase;

class SiteDescriptionRulesTest extends TestCase
{
    public function test_plain_text_strips_tags_and_collapses_whitespace(): void
    {
        $plain = SiteDescriptionRules::plainText("<p>Hello   <strong>world</strong></p>\n\nNext");

        $this->assertSame('Hello world Next', $plain);
    }

    public function test_word_count(): void
    {
        $this->assertSame(0, SiteDescriptionRules::wordCount(''));
        $this->assertSame(2, SiteDescriptionRules::wordCount('Hello world'));
        $this->assertSame(3, SiteDescriptionRules::wordCount("  one   two\tthree "));
    }

    public function test_errors_for_empty_and_short_text(): void
    {
        $this->assertNotEmpty(SiteDescriptionRules::errors(''));
        $this->assertNotEmpty(SiteDescriptionRules::errors('<p><br></p>'));
        $this->assertNotEmpty(SiteDescriptionRules::errors('<p>'.str_repeat('a', 49).'</p>'));
        $this->assertSame([], SiteDescriptionRules::errors('<p>'.str_repeat('a', 50).'</p>'));
    }

    public function test_min_chars_ignores_html_padding(): void
    {
        // Lots of tags, short visible text — must still fail min chars.
        $html = '<p><strong><em>'.str_repeat('x', 20).'</em></strong></p>';
        $errors = SiteDescriptionRules::errors($html);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('50 characters', $errors[0]);
    }

    public function test_max_words_enforced(): void
    {
        $words = implode(' ', array_fill(0, 501, 'word'));
        $errors = SiteDescriptionRules::errors($words);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('500 words', $errors[0]);

        $ok = implode(' ', array_fill(0, 500, 'word'));
        $this->assertSame([], SiteDescriptionRules::errors($ok));
    }

    public function test_excerpt_strips_tags(): void
    {
        $excerpt = SiteDescriptionRules::excerpt('<p>Hello <b>world</b> and more text about the site.</p>', 20);
        $this->assertStringNotContainsString('<', $excerpt);
        $this->assertStringStartsWith('Hello world', $excerpt);
    }
}
