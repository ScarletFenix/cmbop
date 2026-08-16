<?php

namespace App\Services\ContentModeration;

/**
 * Contextual policy scorer — keywords + phrases + domains + intent co-occurrence.
 *
 * Policy stance: clear restricted keywords, intent phrases, and gambling/adult
 * domains (in hrefs or body text) must fail the confidence threshold. Soft scoring
 * previously let single prohibited terms through — that is intentionally strict now.
 */
class ContentModerationEngine
{
    /** Per-window cap so a 10 MB article cannot timeout one regex pass. */
    public const SCORE_TEXT_CHARS = 200000;

    /** Overlap so a keyword cannot hide on a window boundary ("cas" | "ino"). */
    public const SCORE_TEXT_OVERLAP = 128;

    /** Max windows scored in full. Beyond this the article is fail-closed. */
    public const SCORE_TEXT_WINDOWS = 20;

    public static function windowStep(): int
    {
        return self::SCORE_TEXT_CHARS - self::SCORE_TEXT_OVERLAP;
    }

    public static function maxScannableChars(): int
    {
        return self::SCORE_TEXT_CHARS + (self::SCORE_TEXT_WINDOWS - 1) * self::windowStep();
    }

    /**
     * @param  array<string, mixed>  $categories
     * @param  array<int, string|array{url?:string,anchor?:string}>  $links
     * @param  array<int, string>  $extraKeywords
     * @param  array<int, string>  $exceptions
     * @return array{
     *   scores: array<string,int>,
     *   max_confidence:int,
     *   detected_category:?string,
     *   signals:array,
     *   matched_terms: array<int, string>,
     *   blocked_urls: array<int, string>
     * }
     */
    public function score(
        string $title,
        string $text,
        array $links,
        array $categories,
        array $extraKeywords = [],
        array $exceptions = [],
    ): array {
        $merged = [
            'scores' => [],
            'max_confidence' => 0,
            'detected_category' => null,
            'signals' => ['hits' => [], 'blocked_urls' => []],
            'matched_terms' => [],
            'blocked_urls' => [],
        ];

        foreach ($this->policyTextWindows($text) as $window) {
            $merged = $this->mergeScoreResults($merged, $this->scoreWindow(
                $title,
                $window,
                $links,
                $categories,
                $extraKeywords,
                $exceptions,
            ));
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    public function policyTextWindows(string $text): array
    {
        $len = mb_strlen($text);
        if ($len <= self::SCORE_TEXT_CHARS) {
            return [$text];
        }

        $step = self::windowStep();
        $windows = [];
        for ($offset = 0; $offset < $len; $offset += $step) {
            $windows[] = mb_substr($text, $offset, self::SCORE_TEXT_CHARS);
            if (count($windows) >= self::SCORE_TEXT_WINDOWS) {
                break;
            }
            if ($offset + self::SCORE_TEXT_CHARS >= $len) {
                break;
            }
        }

        return $windows;
    }

    /**
     * @param  array<string, mixed>  $categories
     * @param  array<int, string|array{url?:string,anchor?:string}>  $links
     * @param  array<int, string>  $extraKeywords
     * @param  array<int, string>  $exceptions
     * @return array{
     *   scores: array<string,int>,
     *   max_confidence:int,
     *   detected_category:?string,
     *   signals:array,
     *   matched_terms: array<int, string>,
     *   blocked_urls: array<int, string>
     * }
     */
    protected function scoreWindow(
        string $title,
        string $text,
        array $links,
        array $categories,
        array $extraKeywords,
        array $exceptions,
    ): array {
        $rawHaystack = mb_strtolower($this->splitCamelCase($title."\n".$text));
        $haystack = $this->applyExceptions($this->deobfuscate($rawHaystack), $exceptions);
        $urlStrings = $this->normalizeLinkList($links);
        $urlStrings = $this->enrichLinksFromContent($urlStrings, $haystack, $categories);
        $linkHosts = array_map(fn (string $u) => $this->hostForMatch($u), $urlStrings);
        $linkBlob = mb_strtolower($this->splitCamelCase(implode(' ', array_merge($urlStrings, $linkHosts))));
        if ($linkBlob !== '') {
            $haystack = trim($haystack."\n".$this->deobfuscate($linkBlob));
        }
        $tightHaystack = $this->tightenHaystack($haystack);

        $scores = [];
        $signals = ['hits' => []];
        $allMatched = [];
        $allBlockedUrls = [];

        foreach ($categories as $key => $cat) {
            if (empty($cat['enabled'])) {
                continue;
            }

            $points = 0.0;
            $hits = 0;
            $matched = [];
            $blockedUrls = [];
            $hardHit = false;

            $keywords = $this->mergedKeywords($cat);

            foreach ($keywords as $kw) {
                $kw = mb_strtolower(trim((string) $kw));
                if ($kw === '') {
                    continue;
                }
                $count = $this->countTerm($haystack, $kw, $tightHaystack);
                if ($count > 0) {
                    // One clear restricted keyword is enough to fail the default threshold (70).
                    $points += min(95, 78 + ($count - 1) * 6);
                    $hits += $count;
                    $matched[] = $kw;
                    $hardHit = true;
                }
            }

            foreach ($cat['intent_phrases'] ?? [] as $phrase) {
                $phrase = mb_strtolower(trim((string) $phrase));
                if ($phrase !== '' && $this->phrasePresent($haystack, $tightHaystack, $phrase)) {
                    $points += 85;
                    $hits++;
                    $matched[] = $phrase;
                    $hardHit = true;
                }
            }

            foreach ($cat['domains'] ?? [] as $domain) {
                $domain = mb_strtolower(trim((string) $domain));
                if ($domain === '') {
                    continue;
                }

                $urlsForDomain = $this->urlsMatchingDomain($urlStrings, $domain);
                if ($urlsForDomain !== []) {
                    // One blocked destination is enough to fail policy.
                    $points += 95;
                    $hits++;
                    $hardHit = true;
                    $matched[] = $domain;
                    $blockedUrls = array_merge($blockedUrls, $urlsForDomain);
                } elseif ($this->domainMentioned($haystack, $domain) || $this->domainMentioned($linkBlob, $domain)) {
                    $points += 90;
                    $hits++;
                    $hardHit = true;
                    $matched[] = $domain;
                    $blockedUrls[] = $this->syntheticUrlForDomain($domain);
                }
            }

            if ($hits >= 3) {
                $points *= 1.08;
            } elseif ($hits >= 2) {
                $points *= 1.04;
            }

            $weight = (float) ($cat['weight'] ?? 1.0);
            $confidence = (int) min(99, round($points * $weight));

            // Never soft-pedal hard policy hits (keywords, domains, intent).
            if ($hardHit) {
                $confidence = max($confidence, 78);
            }

            $scores[$key] = $confidence;
            $matched = array_values(array_unique($matched));
            $blockedUrls = array_values(array_unique(array_filter($blockedUrls)));
            if ($confidence > 0) {
                $signals['hits'][$key] = [
                    'term_hits' => $hits,
                    'confidence' => $confidence,
                    'matched_terms' => $matched,
                    'blocked_urls' => $blockedUrls,
                    'hard_hit' => $hardHit,
                ];
                $allMatched = array_merge($allMatched, $matched);
                $allBlockedUrls = array_merge($allBlockedUrls, $blockedUrls);
            }
        }

        $customMatched = [];
        $customHits = 0;
        foreach ($extraKeywords as $extra) {
            $extra = mb_strtolower(trim((string) $extra));
            if ($extra !== '' && $this->countTerm($haystack, $extra, $tightHaystack) > 0) {
                $customHits++;
                $customMatched[] = $extra;
            }
        }
        if ($customHits > 0) {
            $customMatched = array_values(array_unique($customMatched));
            $confidence = (int) min(99, max(78, 78 + ($customHits - 1) * 6));
            $scores['custom'] = $confidence;
            $signals['hits']['custom'] = [
                'term_hits' => $customHits,
                'confidence' => $confidence,
                'matched_terms' => $customMatched,
                'blocked_urls' => [],
                'hard_hit' => true,
            ];
            $allMatched = array_merge($allMatched, $customMatched);
        }

        arsort($scores);
        $detected = null;
        $max = 0;
        foreach ($scores as $key => $conf) {
            if ($conf > $max) {
                $max = $conf;
                $detected = $key;
            }
        }

        $allBlockedUrls = array_values(array_unique($allBlockedUrls));
        $signals['blocked_urls'] = $allBlockedUrls;

        return [
            'scores' => $scores,
            'max_confidence' => $max,
            'detected_category' => $max > 0 ? $detected : null,
            'signals' => $signals,
            'matched_terms' => array_values(array_unique($allMatched)),
            'blocked_urls' => $allBlockedUrls,
        ];
    }

    /**
     * @param  array{
     *   scores: array<string,int>,
     *   max_confidence:int,
     *   detected_category:?string,
     *   signals:array,
     *   matched_terms: array<int, string>,
     *   blocked_urls: array<int, string>
     * }  $into
     * @param  array{
     *   scores: array<string,int>,
     *   max_confidence:int,
     *   detected_category:?string,
     *   signals:array,
     *   matched_terms: array<int, string>,
     *   blocked_urls: array<int, string>
     * }  $part
     * @return array{
     *   scores: array<string,int>,
     *   max_confidence:int,
     *   detected_category:?string,
     *   signals:array,
     *   matched_terms: array<int, string>,
     *   blocked_urls: array<int, string>
     * }
     */
    protected function mergeScoreResults(array $into, array $part): array
    {
        foreach ($part['scores'] as $key => $conf) {
            $into['scores'][$key] = max((int) ($into['scores'][$key] ?? 0), (int) $conf);
        }
        arsort($into['scores']);
        $into['max_confidence'] = 0;
        $into['detected_category'] = null;
        foreach ($into['scores'] as $key => $conf) {
            if ($conf > $into['max_confidence']) {
                $into['max_confidence'] = $conf;
                $into['detected_category'] = $conf > 0 ? $key : null;
            }
        }
        $into['matched_terms'] = array_values(array_unique(array_merge(
            $into['matched_terms'],
            $part['matched_terms']
        )));
        $into['blocked_urls'] = array_values(array_unique(array_merge(
            $into['blocked_urls'],
            $part['blocked_urls']
        )));
        $into['signals']['blocked_urls'] = $into['blocked_urls'];
        foreach ($part['signals']['hits'] ?? [] as $key => $hit) {
            $existing = $into['signals']['hits'][$key] ?? null;
            if (! is_array($existing)) {
                $into['signals']['hits'][$key] = $hit;

                continue;
            }
            $into['signals']['hits'][$key] = [
                'term_hits' => max((int) ($existing['term_hits'] ?? 0), (int) ($hit['term_hits'] ?? 0)),
                'confidence' => max((int) ($existing['confidence'] ?? 0), (int) ($hit['confidence'] ?? 0)),
                'matched_terms' => array_values(array_unique(array_merge(
                    $existing['matched_terms'] ?? [],
                    $hit['matched_terms'] ?? []
                ))),
                'blocked_urls' => array_values(array_unique(array_merge(
                    $existing['blocked_urls'] ?? [],
                    $hit['blocked_urls'] ?? []
                ))),
                'hard_hit' => (bool) ($existing['hard_hit'] ?? false) || (bool) ($hit['hard_hit'] ?? false),
            ];
        }

        return $into;
    }

    /**
     * @param  array<int, string|array{url?:string,anchor?:string}|mixed>  $links
     * @return list<string>
     */
    public function normalizeLinkList(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if (is_string($link)) {
                $url = $this->percentDecode(trim($link));
            } elseif (is_array($link)) {
                $url = $this->percentDecode(trim((string) ($link['url'] ?? '')));
            } else {
                continue;
            }
            if ($url === '') {
                continue;
            }
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            }
            // Promote bare www./domain mentions into absolute URLs for host matching.
            if (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
                if (preg_match('#^(www\.)?[a-z0-9.-]+\.[a-z]{2,}(/.*)?$#i', $url)) {
                    $url = 'https://'.ltrim($url, '/');
                }
            }
            $out[] = $url;
        }

        return array_values(array_unique($out));
    }

    public function hostForMatch(string $url): string
    {
        $url = $this->percentDecode($url);
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            // Bare domain / path fragments
            $host = preg_replace('#^(https?:)?//#i', '', $url) ?? $url;
            $host = explode('/', $host)[0] ?? $host;
        }

        $host = mb_strtolower($host);
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = mb_strtolower($ascii);
            }
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Pull absolute / www / bare restricted-looking URLs out of article body text.
     *
     * @return list<string>
     */
    public function extractUrlsFromText(string $text): array
    {
        $text = $this->deobfuscate(mb_strtolower($text));
        $found = [];

        if (preg_match_all('#https?://[^\s<>"\')\]]+#iu', $text, $m)) {
            foreach ($m[0] as $url) {
                $found[] = rtrim((string) $url, '.,);]');
            }
        }

        if (preg_match_all('#(?<![\w./])(?:www\.)[a-z0-9.-]+\.[a-z]{2,}(?:/[^\s<>"\')\]]*)?#iu', $text, $m2)) {
            foreach ($m2[0] as $url) {
                $found[] = 'https://'.ltrim((string) $url, '/');
            }
        }

        return $this->normalizeLinkList($found);
    }

    /**
     * @param  list<string>  $urls
     * @param  array<string, mixed>  $categories
     * @return list<string>
     */
    public function enrichLinksFromContent(array $urls, string $haystack, array $categories): array
    {
        $urls = array_values(array_unique(array_merge($urls, $this->extractUrlsFromText($haystack))));

        foreach ($categories as $cat) {
            if (empty($cat['enabled'])) {
                continue;
            }
            foreach ($cat['domains'] ?? [] as $domain) {
                $domain = mb_strtolower(trim((string) $domain));
                if ($domain === '' || ! str_contains($domain, '.')) {
                    continue;
                }
                if ($this->domainMentioned($haystack, $domain)) {
                    $urls[] = $this->syntheticUrlForDomain($domain);
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    protected function urlsMatchingDomain(array $urls, string $domain): array
    {
        $domain = mb_strtolower(trim($domain));
        $matched = [];
        foreach ($urls as $url) {
            $host = $this->hostForMatch($url);
            $hay = mb_strtolower($url);
            if ($host !== '' && $this->hostMatchesDomain($host, $domain)) {
                $matched[] = $url;

                continue;
            }
            if ($this->domainMentioned($hay, $domain)) {
                $matched[] = $url;
            }
        }

        return array_values(array_unique($matched));
    }

    protected function hostMatchesDomain(string $host, string $domain): bool
    {
        $host = mb_strtolower($host);
        $domain = mb_strtolower($domain);

        if ($domain === '') {
            return false;
        }

        // Brand tokens without a TLD (bet365, pornhub, pokerstars).
        if (! str_contains($domain, '.')) {
            return str_contains($host, $domain);
        }

        return $host === $domain
            || str_ends_with($host, '.'.$domain)
            || str_contains($host, $domain);
    }

    protected function domainMentioned(string $haystack, string $domain): bool
    {
        $domain = mb_strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        if (str_contains($haystack, $domain)) {
            return true;
        }

        // Obfuscations: stake[dot]com, stake (dot) com, stake . com
        if (str_contains($domain, '.')) {
            $parts = explode('.', $domain);
            $escaped = array_map(static fn (string $p) => preg_quote($p, '/'), $parts);
            $flex = implode('[\s\[\(\{]*(?:dot)?[\s\]\)\}]*\.?[\s\[\(\{]*', $escaped);

            return (bool) preg_match('/'.$flex.'/iu', $haystack);
        }

        return (bool) preg_match('/\b'.preg_quote($domain, '/').'\b/u', $haystack);
    }

    protected function syntheticUrlForDomain(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        if (! str_contains($domain, '.')) {
            return 'https://'.$domain.'.com/';
        }

        return 'https://'.$domain.'/';
    }

    /**
     * Normalize common link cloaking before keyword/domain scans.
     */
    /**
     * Insert a break before capitals so BestOnlineCasinoBonus still matches casino.
     */
    public function splitCamelCase(string $text): string
    {
        return preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $text) ?? $text;
    }

    public function deobfuscate(string $text): string
    {
        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $text) {
                break;
            }
            $text = $next;
        }
        $text = preg_replace('/\[\s*dot\s*\]/iu', '.', $text) ?? $text;
        $text = preg_replace('/\(\s*dot\s*\)/iu', '.', $text) ?? $text;
        $text = preg_replace('/\{\s*dot\s*\}/iu', '.', $text) ?? $text;
        $text = preg_replace('/\s+dot\s+/iu', '.', $text) ?? $text;
        // "stake . com" / "bet365 . com"
        $text = preg_replace('/(\w)\s*\.\s*(\w)/u', '$1.$2', $text) ?? $text;
        $text = str_ireplace(['hxxps://', 'hxxp://', 'h**ps://'], ['https://', 'http://', 'https://'], $text);
        // Soft hyphen / zero-width / bidi marks used to split "casino" into "cas<zw>ino".
        $text = preg_replace(
            '/[\x{00AD}\x{034F}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{FFF9}-\x{FFFB}]/u',
            '',
            $text
        ) ?? $text;

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KD);
            if (is_string($normalized) && $normalized !== '') {
                $text = $normalized;
            }
        }
        // Combining slashes / accents used to hide "casino" as "c̸a̸s̸i̸n̸o̸".
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;
        // After NFKD, fullwidth %HH becomes ASCII so cas％６９no → casino.
        $text = $this->percentDecode($text);
        // CSS hex escapes in inline style urls: cas\69no → casino.
        $text = $this->cssDecode($text);

        $text = strtr($text, self::latinConfusables());

        return mb_strtolower($text);
    }

    /**
     * Cyrillic / Greek / other lookalikes used to hide Latin keywords ("caѕino").
     *
     * @return array<string, string>
     */
    protected static function latinConfusables(): array
    {
        return [
            'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c',
            'у' => 'y', 'х' => 'x', 'і' => 'i', 'ј' => 'j', 'ѕ' => 's',
            'ԁ' => 'd', 'ɡ' => 'g', 'ԛ' => 'q', 'ԝ' => 'w',
            'α' => 'a', 'ο' => 'o', 'ρ' => 'p', 'τ' => 't', 'υ' => 'y',
            'χ' => 'x', 'ι' => 'i', 'ν' => 'v', 'η' => 'n', 'κ' => 'k',
            'ϲ' => 'c', 'ᴄ' => 'c', 'ꜱ' => 's',
        ];
    }

    /**
     * Join letter-split evasions ("cas-ino", "c a s i n o") without
     * destroying hyphen boundaries on the original haystack.
     */
    public function tightenHaystack(string $haystack): string
    {
        return preg_replace('/(?<=\p{L})[\s\-\._\\\\\/]+(?=\p{L})/u', '', $haystack) ?? $haystack;
    }

    /**
     * Digit / symbol substitutions used to hide keywords ("casin0", "sl0ts").
     */
    public function leetFold(string $haystack): string
    {
        return strtr($haystack, [
            '0' => 'o',
            '1' => 'i',
            '3' => 'e',
            '4' => 'a',
            '5' => 's',
            '7' => 't',
            '@' => 'a',
            '$' => 's',
        ]);
    }

    /**
     * Unfold %69 / %2569 cloaking in URLs, filenames, and body copy.
     */
    public function percentDecode(string $text): string
    {
        $decoded = $text;
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($decoded);
            $next = str_replace("\0", '', $next);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        return $decoded;
    }

    /**
     * Unfold CSS hex escapes used in inline style urls ("cas\69no").
     */
    public function cssDecode(string $text): string
    {
        $decoded = preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})(?:\s|(?![0-9a-fA-F]))/u',
            static function (array $m): string {
                $codepoint = hexdec($m[1]);
                if ($codepoint <= 0 || $codepoint > 0x10FFFF) {
                    return '';
                }
                $char = mb_chr($codepoint, 'UTF-8');

                return is_string($char) ? $char : '';
            },
            $text
        );

        return is_string($decoded) ? $decoded : $text;
    }

    /**
     * @param  array<string, mixed>  $cat
     * @return list<string>
     */
    public function mergedKeywords(array $cat): array
    {
        $keywords = array_map('strval', $cat['keywords'] ?? []);
        $byLocale = $cat['keywords_by_locale'] ?? [];
        if (is_array($byLocale)) {
            foreach ($byLocale as $list) {
                if (! is_array($list)) {
                    continue;
                }
                foreach ($list as $kw) {
                    $keywords[] = (string) $kw;
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($k) => trim((string) $k),
            $keywords
        ), static fn ($k) => $k !== '')));
    }

    protected function countTerm(string $haystack, string $term, ?string $tightHaystack = null): int
    {
        $count = 0;
        foreach (array_filter([$haystack, $tightHaystack]) as $candidate) {
            $count = max($count, $this->countTermIn($candidate, $term));
            $leet = $this->leetFold($candidate);
            if ($leet !== $candidate) {
                $count = max($count, $this->countTermIn($leet, $term));
            }
        }

        return $count;
    }

    protected function countTermIn(string $haystack, string $term): int
    {
        if (str_contains($term, ' ')) {
            return substr_count($haystack, $term);
        }

        // Underscore is a separator (filenames, slugs, URLs), not a letter.
        // `best_online_casino_bonus.jpg` must still match `casino`.
        return preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $haystack) ?: 0;
    }

    protected function phrasePresent(string $haystack, string $tightHaystack, string $phrase): bool
    {
        $leetPhrase = $this->leetFold($phrase);
        if (str_contains($haystack, $phrase) || str_contains($this->leetFold($haystack), $leetPhrase)) {
            return true;
        }

        $tightPhrase = preg_replace('/[\s\-\._]+/u', '', $phrase) ?? $phrase;
        if ($tightPhrase === '') {
            return false;
        }

        return str_contains($tightHaystack, $tightPhrase)
            || str_contains($this->leetFold($tightHaystack), $this->leetFold($tightPhrase));
    }

    /**
     * @param  array<int, string|array>  $exceptions
     */
    protected function applyExceptions(string $haystack, array $exceptions): string
    {
        foreach ($exceptions as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $haystack = str_ireplace($value, ' ', $haystack);
            } elseif (is_string($key) && is_array($value)) {
                foreach ($value as $phrase) {
                    $haystack = str_ireplace((string) $phrase, ' ', $haystack);
                }
            }
        }

        return $haystack;
    }
}
