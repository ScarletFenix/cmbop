<?php

namespace Tests\Unit;

use App\Services\BlogHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class BlogHtmlSanitizerTest extends TestCase
{
    private BlogHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new BlogHtmlSanitizer;
    }

    public function test_script_tags_are_removed(): void
    {
        $clean = $this->sanitizer->sanitize('<p>Hello</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringContainsString('<p>Hello</p>', $clean);
    }

    public function test_inline_event_handlers_are_stripped(): void
    {
        $clean = $this->sanitizer->sanitize('<p onclick="alert(1)">Text</p><div onmouseover=alert(2)>x</div>');

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onmouseover', $clean);
        $this->assertStringContainsString('Text', $clean);
    }

    public function test_javascript_links_are_dropped_but_text_kept(): void
    {
        $clean = $this->sanitizer->sanitize('<a href="javascript:alert(1)">Click</a>');

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('Click', $clean);
    }

    public function test_http_links_are_kept_and_made_safe(): void
    {
        $clean = $this->sanitizer->sanitize('<a href="https://example.com/post">Read</a>');

        $this->assertStringContainsString('href="https://example.com/post"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
    }

    public function test_editor_formatting_tags_survive(): void
    {
        $html = '<h1>T</h1><h5>Sub</h5><h6>Deep</h6><p><strong>b</strong><em>i</em></p>'
            .'<ul><li>one</li></ul><blockquote>q</blockquote><pre><code>x</code></pre>';

        $clean = $this->sanitizer->sanitize($html);

        foreach (['<h1>', '<h5>', '<h6>', '<strong>', '<em>', '<ul>', '<li>', '<blockquote>', '<pre>', '<code>'] as $tag) {
            $this->assertStringContainsString($tag, $clean, $tag.' should be preserved');
        }
    }

    public function test_inline_color_spans_are_preserved(): void
    {
        $clean = $this->sanitizer->sanitize('<p><span style="color: rgb(230, 0, 0);">Red</span></p>');

        $this->assertStringContainsString('Red', $clean);
        $this->assertStringContainsString('<span', $clean);
    }

    public function test_images_keep_https_and_storage_sources(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<img src="https://cdn.example.com/a.png" alt="A">'
            .'<img src="/storage/blogs/content/b.png" alt="B">'
            .'<img src="/media/blogs/content/c.webp" alt="C">'
            .'<img src="/storage/uploads/other.png" alt="D">'
        );

        $this->assertStringContainsString('src="https://cdn.example.com/a.png"', $clean);
        $this->assertStringContainsString('src="/media/blogs/content/b.png"', $clean);
        $this->assertStringContainsString('src="/media/blogs/content/c.webp"', $clean);
        $this->assertStringContainsString('src="/storage/uploads/other.png"', $clean);
        $this->assertStringNotContainsString('/storage/blogs/content/b.png', $clean);
    }

    public function test_rewrite_storage_blog_urls_is_idempotent(): void
    {
        $html = '<img src="https://example.test/storage/blogs/content/x.jpg">';
        $once = BlogHtmlSanitizer::rewriteStorageBlogUrls($html);
        $twice = BlogHtmlSanitizer::rewriteStorageBlogUrls($once);

        $this->assertSame('<img src="/media/blogs/content/x.jpg">', $once);
        $this->assertSame($once, $twice);
    }

    public function test_encode_for_editor_rewrites_storage_blog_urls(): void
    {
        $encoded = BlogHtmlSanitizer::encodeForEditor('<p><img src="/storage/blogs/content/demo.jpg"></p>');

        $this->assertStringContainsString('/media/blogs/content/demo.jpg', $encoded);
        $this->assertStringNotContainsString('/storage/blogs/content/demo.jpg', $encoded);
    }

    public function test_image_with_untrusted_media_prefix_is_removed(): void
    {
        $clean = $this->sanitizer->sanitize('<img src="/media/sites/cover.webp" alt="No">');

        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsString('/media/sites/', $clean);
    }

    public function test_image_with_unsupported_scheme_is_removed(): void
    {
        $clean = $this->sanitizer->sanitize('<img src="javascript:alert(1)">');

        $this->assertStringNotContainsString('<img', $clean);
    }

    public function test_youtube_embed_is_kept(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<iframe class="ql-video" src="https://www.youtube.com/embed/abc123"></iframe>'
        );

        $this->assertStringContainsString('<iframe', $clean);
        $this->assertStringContainsString('https://www.youtube.com/embed/abc123', $clean);
        $this->assertStringContainsString('allowfullscreen', $clean);
    }

    public function test_untrusted_iframe_is_removed_without_stray_tags(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<p>before</p><iframe src="https://evil.example.com/x"></iframe><p>after</p>'
        );

        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('evil.example.com', $clean);
        $this->assertStringContainsString('before', $clean);
        $this->assertStringContainsString('after', $clean);
    }

    public function test_empty_editor_output_becomes_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize('<p><br></p>'));
        $this->assertSame('', $this->sanitizer->sanitize(''));
        $this->assertSame('', $this->sanitizer->sanitize(null));
    }

    public function test_blank_quill_html_is_detected(): void
    {
        $this->assertTrue(BlogHtmlSanitizer::isBlank('<p><br></p>'));
        $this->assertTrue(BlogHtmlSanitizer::isBlank('<p></p>'));
        $this->assertTrue(BlogHtmlSanitizer::isBlank('<p><br></p><p><br></p>'));
        $this->assertTrue(BlogHtmlSanitizer::isBlank('   '));
        $this->assertFalse(BlogHtmlSanitizer::isBlank('<p>Hello</p>'));
        $this->assertFalse(BlogHtmlSanitizer::isBlank('<p><img src="/storage/blogs/content/a.png" alt=""></p>'));
    }
}
