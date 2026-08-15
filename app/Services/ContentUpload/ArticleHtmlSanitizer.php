<?php

namespace App\Services\ContentUpload;

/**
 * Sanitize advertiser article HTML for preview/editor storage.
 */
class ArticleHtmlSanitizer
{
    /**
     * Allowed tags for article body (docs-style editor output).
     */
    private const ALLOWED = '<p><br><strong><b><em><i><u><s><strike><ul><ol><li><a><h1><h2><h3><h4><blockquote><img><span><div>';

    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '' || $html === '<p><br></p>' || $html === '<p></p>') {
            return '';
        }

        $html = $this->stripPreviewChrome($html);

        $clean = strip_tags($html, self::ALLOWED);

        // Drop event handlers / javascript: URLs from remaining tags
        $clean = preg_replace('/\son\w+\s*=\s*("|\').*?\1/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/iu', '', $clean) ?? $clean;

        // Normalize anchors
        $clean = preg_replace_callback(
            '/<a\b([^>]*)>/iu',
            function (array $m): string {
                $attrs = $m[1];
                $href = '';
                if (preg_match('/\bhref\s*=\s*("|\')(.*?)\1/iu', $attrs, $hm)) {
                    $href = trim(html_entity_decode($hm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if ($href === '' || ! preg_match('#^https?://#i', $href)) {
                    return '<a>';
                }

                return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">';
            },
            $clean
        ) ?? $clean;

        // Normalize images
        $clean = preg_replace_callback(
            '/<img\b([^>]*)>/iu',
            function (array $m): string {
                $attrs = $m[1];
                $src = '';
                if (preg_match('/\bsrc\s*=\s*("|\')(.*?)\1/iu', $attrs, $sm)) {
                    $src = trim(html_entity_decode($sm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if ($src === '') {
                    return '';
                }
                // Editor/preview may load /media (Hostinger fallback) then persist /storage.
                if (str_starts_with($src, '/media/')) {
                    $src = '/storage/'.substr($src, strlen('/media/'));
                }
                // Persist hosted files only. data:image paste would store megabytes in HTML.
                $ok = preg_match('#^https?://#i', $src)
                    || str_starts_with($src, '/storage/')
                    || str_starts_with($src, '/media/');
                if (! $ok) {
                    return '';
                }
                $alt = '';
                if (preg_match('/\balt\s*=\s*("|\')(.*?)\1/iu', $attrs, $am)) {
                    $alt = $am[2];
                }

                return '<img src="'.e($src).'" alt="'.e($alt).'">';
            },
            $clean
        ) ?? $clean;

        return trim($clean);
    }

    /**
     * Preview injects Download buttons around images. Those must not persist.
     */
    private function stripPreviewChrome(string $html): string
    {
        $html = preg_replace(
            '/<button\b[^>]*article-img-download[^>]*>[\s\S]*?<\/button>/iu',
            '',
            $html
        ) ?? $html;

        return preg_replace(
            '/<div\b[^>]*article-img-wrap[^>]*>([\s\S]*?)<\/div>/iu',
            '$1',
            $html
        ) ?? $html;
    }

    public function htmlToPlainText(string $html): string
    {
        $withBreaks = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n\n", $html) ?? $html;
        $withBreaks = preg_replace('/<br\s*\/?>/i', "\n", $withBreaks) ?? $withBreaks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<int, array{anchor:string, url:string}>
     */
    public function extractLinksFromHtml(string $html): array
    {
        $links = [];
        if (! preg_match_all('/<a\b[^>]*href=("|\')(https:\/\/[^"\']+)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $seen = [];
        foreach ($matches as $m) {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $anchor = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($anchor === '') {
                $anchor = $url;
            }
            $links[] = ['anchor' => mb_substr($anchor, 0, 120), 'url' => $url];
        }

        return $links;
    }

    public function countWords(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text) ?: []);
    }

    public function countImages(?string $html): int
    {
        if ($html === null || $html === '') {
            return 0;
        }

        return preg_match_all('/<img\b/i', $html) ?: 0;
    }
}
