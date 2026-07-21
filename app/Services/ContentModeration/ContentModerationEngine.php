<?php

namespace App\Services\ContentModeration;

/**
 * Contextual policy scorer — keywords + phrases + domains + intent co-occurrence.
 */
class ContentModerationEngine
{
    /**
     * @param  array<string, mixed>  $categories
     * @param  array<int, string>  $links
     * @param  array<int, string>  $extraKeywords
     * @param  array<int, string>  $exceptions
     * @return array{
     *   scores: array<string,int>,
     *   max_confidence:int,
     *   detected_category:?string,
     *   signals:array,
     *   matched_terms: array<int, string>
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
        $haystack = mb_strtolower($title."\n".$text);
        $haystack = $this->applyExceptions($haystack, $exceptions);
        $linkBlob = mb_strtolower(implode(' ', $links));

        $scores = [];
        $signals = ['hits' => []];
        $allMatched = [];

        foreach ($categories as $key => $cat) {
            if (empty($cat['enabled'])) {
                continue;
            }

            $points = 0.0;
            $hits = 0;
            $matched = [];

            $keywords = $this->mergedKeywords($cat);

            foreach ($keywords as $kw) {
                $kw = mb_strtolower(trim((string) $kw));
                if ($kw === '') {
                    continue;
                }
                $count = $this->countTerm($haystack, $kw);
                if ($count > 0) {
                    $points += min(35, 12 + ($count - 1) * 6);
                    $hits += $count;
                    $matched[] = $kw;
                }
            }

            foreach ($cat['intent_phrases'] ?? [] as $phrase) {
                $phrase = mb_strtolower(trim((string) $phrase));
                if ($phrase !== '' && str_contains($haystack, $phrase)) {
                    $points += 22;
                    $hits++;
                    $matched[] = $phrase;
                }
            }

            foreach ($cat['domains'] ?? [] as $domain) {
                $domain = mb_strtolower(trim((string) $domain));
                if ($domain !== '' && (str_contains($linkBlob, $domain) || str_contains($haystack, $domain))) {
                    $points += 28;
                    $hits++;
                    $matched[] = $domain;
                }
            }

            foreach ($extraKeywords as $extra) {
                $extra = mb_strtolower(trim((string) $extra));
                if ($extra !== '' && str_contains($haystack, $extra)) {
                    $points += 10;
                    $hits++;
                    $matched[] = $extra;
                }
            }

            if ($hits >= 3) {
                $points *= 1.25;
            } elseif ($hits >= 2) {
                $points *= 1.12;
            }

            $weight = (float) ($cat['weight'] ?? 1.0);
            $confidence = (int) min(99, round($points * $weight));

            if ($hits === 1 && $confidence < 40) {
                $confidence = (int) round($confidence * 0.55);
            }

            $scores[$key] = $confidence;
            $matched = array_values(array_unique($matched));
            if ($confidence > 0) {
                $signals['hits'][$key] = [
                    'term_hits' => $hits,
                    'confidence' => $confidence,
                    'matched_terms' => $matched,
                ];
                $allMatched = array_merge($allMatched, $matched);
            }
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

        return [
            'scores' => $scores,
            'max_confidence' => $max,
            'detected_category' => $max > 0 ? $detected : null,
            'signals' => $signals,
            'matched_terms' => array_values(array_unique($allMatched)),
        ];
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

    protected function countTerm(string $haystack, string $term): int
    {
        if (str_contains($term, ' ')) {
            return substr_count($haystack, $term);
        }

        return preg_match_all('/\b'.preg_quote($term, '/').'\b/u', $haystack) ?: 0;
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
