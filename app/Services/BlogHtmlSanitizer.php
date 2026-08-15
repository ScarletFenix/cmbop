<?php

namespace App\Services;

/**
 * Sanitize stored blog HTML (Quill editor output) before it is rendered.
 *
 * Blog bodies are saved as raw HTML, so anything rendered with {!! !!} has to
 * pass through here first.
 */
class BlogHtmlSanitizer
{
    /**
     * Tags the blog editor can legitimately produce.
     */
    private const ALLOWED = '<p><br><hr><strong><b><em><i><u><s><strike><ul><ol><li>'
        .'<a><h1><h2><h3><h4><h5><h6><blockquote><pre><code><img><span><div>'
        .'<figure><figcaption><table><thead><tbody><tr><th><td><iframe>';

    /**
     * Hosts allowed for the editor's video embeds.
     */
    private const EMBED_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
    ];

    /**
     * Quill's unused locale tabs submit this instead of an empty string.
     */
    public static function isEmptyHtml(?string $html): bool
    {
        $html = trim((string) $html);

        return $html === '' || $html === '<p><br></p>' || $html === '<p></p>';
    }

    /**
     * Encode stored HTML for a <script type="application/json"> payload.
     * JSON_HEX_TAG prevents </script> in the body from breaking the edit page.
     */
    public static function encodeForScript(?string $html): string
    {
        return json_encode(
            (string) $html,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '""';
    }

    public function sanitize(?string $html): string
    {
        if (self::isEmptyHtml($html)) {
            return '';
        }

        $html = trim((string) $html);

        // strip_tags keeps inner text, so remove these elements with their contents first.
        $html = preg_replace('/<(script|style|noscript|template)\b[^>]*>.*?<\/\1>/isu', '', $html) ?? $html;
        $html = preg_replace('/<(script|style|noscript|template)\b[^>]*>/iu', '', $html) ?? $html;

        $clean = strip_tags($html, self::ALLOWED);

        // Drop event handlers and javascript: URLs from whatever survived.
        $clean = preg_replace('/\son\w+\s*=\s*("|\').*?\1/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\son\w+\s*=\s*[^\s>]+/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s(href|src)\s*=\s*("|\')\s*(javascript|data\s*:\s*text\/html|vbscript):[^"\']*\2/iu', '', $clean) ?? $clean;

        $clean = $this->normalizeAnchors($clean);
        $clean = $this->normalizeImages($clean);
        $clean = $this->normalizeIframes($clean);

        return trim($clean);
    }

    private function normalizeAnchors(string $html): string
    {
        return preg_replace_callback(
            '/<a\b([^>]*)>/iu',
            function (array $m): string {
                $href = $this->attribute($m[1], 'href');
                if ($href === '' || ! preg_match('~^(https?://|/|mailto:|#)~i', $href)) {
                    return '<a>';
                }

                return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">';
            },
            $html
        ) ?? $html;
    }

    private function normalizeImages(string $html): string
    {
        return preg_replace_callback(
            '/<img\b([^>]*)>/iu',
            function (array $m): string {
                $src = $this->attribute($m[1], 'src');
                $allowed = $src !== '' && (
                    preg_match('#^https?://#i', $src)
                    || str_starts_with($src, '/storage/')
                    || str_starts_with($src, '/media/blogs/')
                    || str_starts_with($src, 'data:image/')
                );
                if (! $allowed) {
                    return '';
                }

                return '<img src="'.e($src).'" alt="'.e($this->attribute($m[1], 'alt')).'">';
            },
            $html
        ) ?? $html;
    }

    private function normalizeIframes(string $html): string
    {
        $accepted = [];

        // Match the whole element so a rejected embed leaves no stray closing tag.
        $clean = preg_replace_callback(
            '/<iframe\b([^>]*)>.*?<\/iframe>/isu',
            function (array $m) use (&$accepted): string {
                $src = $this->attribute($m[1], 'src');
                if ($src === '' || ! preg_match('#^https://#i', $src)) {
                    return '';
                }

                $host = strtolower((string) parse_url($src, PHP_URL_HOST));
                if (! in_array($host, self::EMBED_HOSTS, true)) {
                    return '';
                }

                $token = '%%BLOG_EMBED_'.count($accepted).'%%';
                $accepted[$token] = '<iframe src="'.e($src).'" loading="lazy" allowfullscreen '
                    .'referrerpolicy="strict-origin-when-cross-origin" frameborder="0"></iframe>';

                return $token;
            },
            $html
        ) ?? $html;

        // Drop unpaired iframe tags left over from malformed input, then restore
        // the embeds that passed the host check.
        $clean = preg_replace('/<iframe\b[^>]*>/iu', '', $clean) ?? $clean;
        $clean = str_ireplace('</iframe>', '', $clean);

        return strtr($clean, $accepted);
    }

    private function attribute(string $attrs, string $name): string
    {
        if (! preg_match('/\b'.preg_quote($name, '/').'\s*=\s*("|\')(.*?)\1/iu', $attrs, $m)) {
            return '';
        }

        return trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
