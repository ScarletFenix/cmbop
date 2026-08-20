<?php

namespace App\Services\ContentModeration;

class ContentQualityAnalyzer
{
    /**
     * @var list<string>
     */
    public const DEFAULT_SHORTENER_HOSTS = [
        'bit.ly',
        'tinyurl.com',
        't.co',
        'goo.gl',
        'is.gd',
        'ow.ly',
        'buff.ly',
        'cutt.ly',
        'rebrand.ly',
        'shorturl.at',
        'rb.gy',
        'tiny.cc',
        'lnkd.in',
        't.ly',
        'tiny.one',
    ];

    /**
     * @param  array<int, string>  $links
     * @return array{checks: array<int, array{key:string,label:string,status:string,detail:string}>, score:int, blocking_issues:array<int,string>}
     */
    public function analyze(string $text, string $html, array $links, array $qualityConfig): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordCount = count($words);
        $sampleWords = count($words) > 15000 ? array_slice($words, 0, 15000) : $words;
        $min = (int) ($qualityConfig['min_word_count'] ?? 500);
        $warn = (int) ($qualityConfig['warn_word_count'] ?? 300);
        $maxLinks = (int) ($qualityConfig['max_external_links'] ?? 15);

        $checks = [];
        $blocking = [];

        // Word count stays advisory even when quality blocks are on — the hard
        // gates are placeholder text, outbound-link count, and URL shorteners.
        if ($wordCount >= $min) {
            $checks[] = $this->check('word_count', 'Word Count', 'pass', number_format($wordCount).' words');
        } elseif ($wordCount >= $warn) {
            $checks[] = $this->check('word_count', 'Word Count', 'warn', number_format($wordCount)." words (recommended ≥ {$min})");
        } else {
            $checks[] = $this->check('word_count', 'Word Count', 'fail', number_format($wordCount)." words (recommended ≥ {$min})");
        }

        // Readability (Flesch-like approximation)
        $readability = $this->readabilityScore($text, $sampleWords);
        $checks[] = $this->check(
            'readability',
            'Readability',
            $readability['status'],
            $readability['label'].' ('.$readability['score'].')'
        );

        // Headings
        $hasHeadings = (bool) preg_match('/<h[1-3][^>]*>/i', $html)
            || (bool) preg_match('/^#{1,3}\s+\S+/m', $text)
            || $this->hasLikelyHeadings($text);
        $checks[] = $this->check(
            'headings',
            'Headings',
            $hasHeadings ? 'pass' : 'warn',
            $hasHeadings ? 'Valid structure detected' : 'No clear H1/H2-style headings found'
        );

        // Placeholder
        $hasPlaceholder = (bool) preg_match('/lorem ipsum|dolor sit amet|placeholder text|your text here|insert content/i', $text);
        if ($hasPlaceholder) {
            $checks[] = $this->check('placeholder', 'Placeholder Text', 'fail', 'Placeholder / dummy text detected');
            if (! empty($qualityConfig['block_placeholder_text']) || ! empty($qualityConfig['block_on_quality_failure'])) {
                $blocking[] = 'placeholder';
            }
        } else {
            $checks[] = $this->check('placeholder', 'Placeholder Text', 'pass', 'None detected');
        }

        // Keyword stuffing heuristic
        $stuffing = $this->keywordStuffingRatio($sampleWords);
        $checks[] = $this->check(
            'keyword_stuffing',
            'Keyword Density',
            $stuffing > 0.12 ? 'warn' : 'pass',
            $stuffing > 0.12 ? 'Possible keyword stuffing' : 'Looks balanced'
        );

        // Links — shorteners are always reported, even when the article also
        // exceeds the outbound-link cap, so the author can fix both at once.
        // Count ignores google.com docs/maps links; shortener detection still
        // reads those URLs plus bare bit.ly/… text that never became an href.
        $external = array_values(array_filter($links, fn ($l) => ! str_contains(strtolower($l), 'google.com')));
        $shorteners = $this->shortenerUrlsFromHaystack($text, $html, $links, $qualityConfig);
        $blockQuality = ! empty($qualityConfig['block_on_quality_failure']);
        $tooManyLinks = count($external) > $maxLinks;
        if ($tooManyLinks) {
            $checks[] = $this->check('external_links', 'External Links', 'fail', count($external)." found (maximum {$maxLinks})");
            if ($blockQuality) {
                $blocking[] = 'external_links';
            }
        } elseif ($shorteners !== []) {
            $checks[] = $this->check('external_links', 'External Links', 'fail', count($shorteners).' URL shortener(s) are not allowed');
        } else {
            $checks[] = $this->check('external_links', 'External Links', 'pass', count($external).' found');
        }
        if ($shorteners !== []) {
            $blocking[] = 'url_shortener';
            if ($tooManyLinks) {
                $checks[] = $this->check('url_shortener', 'URL shorteners', 'fail', count($shorteners).' URL shortener(s) are not allowed');
            }
        }

        $pass = count(array_filter($checks, fn ($c) => $c['status'] === 'pass'));
        $score = (int) round(($pass / max(count($checks), 1)) * 100);

        return [
            'checks' => $checks,
            'score' => $score,
            'blocking_issues' => array_values(array_unique($blocking)),
            'word_count' => $wordCount,
            'external_link_count' => count($external),
            'shortener_urls' => $shorteners,
        ];
    }

    /**
     * @param  array<string, mixed>  $qualityConfig
     */
    public function isShortener(string $url, array $qualityConfig = []): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            $host = strtolower((string) parse_url('https://'.ltrim($url, '/'), PHP_URL_HOST));
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host === '') {
            return false;
        }

        foreach ($this->shortenerHosts($qualityConfig) as $known) {
            $known = strtolower(trim((string) $known));
            if ($known === '') {
                continue;
            }
            if ($host === $known || str_ends_with($host, '.'.$known)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $qualityConfig
     * @return list<string>
     */
    public function shortenerHosts(array $qualityConfig = []): array
    {
        $hosts = $qualityConfig['shortener_hosts'] ?? self::DEFAULT_SHORTENER_HOSTS;
        if (! is_array($hosts) || $hosts === []) {
            return self::DEFAULT_SHORTENER_HOSTS;
        }

        return array_values(array_filter(array_map('strval', $hosts)));
    }

    /**
     * @param  array<int, string>  $links
     * @param  array<string, mixed>  $qualityConfig
     * @return list<string>
     */
    public function shortenerUrlsFromHaystack(string $text, string $html, array $links, array $qualityConfig = []): array
    {
        $found = [];
        foreach ($links as $link) {
            $link = trim((string) $link);
            if ($link !== '' && $this->isShortener($link, $qualityConfig)) {
                $found[] = $link;
            }
        }

        $hosts = array_values(array_filter(array_map(
            static fn ($host) => preg_quote(strtolower(trim((string) $host)), '#'),
            $this->shortenerHosts($qualityConfig)
        )));
        if ($hosts === []) {
            return array_values(array_unique($found));
        }

        $haystack = $text."\n".$html;
        $pattern = '#(?:https?://)?(?:www\.)?(?:'.implode('|', $hosts).')/[^\s<>"\']+#i';
        if (preg_match_all($pattern, $haystack, $matches)) {
            foreach ($matches[0] as $url) {
                $found[] = rtrim((string) $url, '.,);]');
            }
        }

        return array_values(array_unique($found));
    }

    protected function check(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }

    /**
     * @param  array<int, string>|null  $words
     */
    protected function readabilityScore(string $text, ?array $words = null): array
    {
        $sample = mb_strlen($text) > 80000 ? mb_substr($text, 0, 80000) : $text;
        $sentences = preg_split('/[.!?]+/u', $sample, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = $words ?? (preg_split('/\s+/u', trim($sample), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $syllables = 0;
        foreach ($words as $w) {
            $syllables += max(1, preg_match_all('/[aeiouy]+/i', $w));
        }
        $sc = max(count($sentences), 1);
        $wc = max(count($words), 1);
        // Flesch Reading Ease approximation
        $score = (int) round(206.835 - 1.015 * ($wc / $sc) - 84.6 * ($syllables / $wc));
        $score = max(0, min(100, $score));

        if ($score >= 60) {
            return ['score' => $score, 'status' => 'pass', 'label' => 'Good'];
        }
        if ($score >= 40) {
            return ['score' => $score, 'status' => 'warn', 'label' => 'Fair'];
        }

        return ['score' => $score, 'status' => 'warn', 'label' => 'Difficult'];
    }

    protected function hasLikelyHeadings(string $text): bool
    {
        $lines = preg_split('/\n+/', $text) ?: [];
        $shortLines = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && mb_strlen($line) <= 80 && ! str_ends_with($line, '.')) {
                $shortLines++;
            }
        }

        return $shortLines >= 2;
    }

    /**
     * @param  array<int, string>  $words
     */
    protected function keywordStuffingRatio(array $words): float
    {
        if (count($words) < 40) {
            return 0.0;
        }
        $freq = [];
        foreach ($words as $w) {
            $k = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $w) ?? '');
            if (mb_strlen($k) < 4) {
                continue;
            }
            $freq[$k] = ($freq[$k] ?? 0) + 1;
        }
        if (! $freq) {
            return 0.0;
        }
        arsort($freq);
        $top = (int) reset($freq);

        return $top / max(count($words), 1);
    }
}
