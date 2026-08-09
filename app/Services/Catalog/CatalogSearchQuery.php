<?php

namespace App\Services\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Advertiser catalog free-text search: metric tokens → range filters,
 * tighter name/category matching, and relevance ordering.
 */
class CatalogSearchQuery
{
    /** Below this, only prefix-match site names (no category substring noise). */
    public const MIN_CONTAINS_LENGTH = 3;

    /**
     * Split raw search into leftover text + DA/DR/traffic/price ranges.
     *
     * Supported metric tokens (case-insensitive):
     * - da>40, dr>=50, traffic<10k, price<=100, da:30
     * - da 40+, traffic 5k+
     * - price 50-200, da 30–60
     *
     * Explicit range inputs on the form always win over parsed tokens.
     *
     * @return array{text: string, ranges: array<string, int>}
     */
    public function parse(string $raw): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        $ranges = [];

        if ($text === '') {
            return ['text' => '', 'ranges' => []];
        }

        // metric 30-60 / metric 5k-10k
        $text = preg_replace_callback(
            '/\b(da|dr|traffic|price)\s+(\d+(?:\.\d+)?)([km])?\s*[-–]\s*(\d+(?:\.\d+)?)([km])?\b/iu',
            function (array $m) use (&$ranges): string {
                $metric = strtolower($m[1]);
                $min = $this->metricNumber($m[2], $m[3] ?? null);
                $max = $this->metricNumber($m[4], $m[5] ?? null);
                if ($min !== null) {
                    $ranges[$metric.'_min'] = $min;
                }
                if ($max !== null) {
                    $ranges[$metric.'_max'] = $max;
                }

                return ' ';
            },
            $text
        ) ?? $text;

        // metric 40+ / metric 10k+
        $text = preg_replace_callback(
            '/\b(da|dr|traffic|price)\s+(\d+(?:\.\d+)?)([km])?\s*\+/iu',
            function (array $m) use (&$ranges): string {
                $metric = strtolower($m[1]);
                $min = $this->metricNumber($m[2], $m[3] ?? null);
                if ($min !== null) {
                    $ranges[$metric.'_min'] = $min;
                }

                return ' ';
            },
            $text
        ) ?? $text;

        // metric>40, metric>=40, metric:40, metric=40, …
        $text = preg_replace_callback(
            '/\b(da|dr|traffic|price)\s*(>=|<=|>|<|=|:)\s*(\d+(?:\.\d+)?)([km])?\b/iu',
            function (array $m) use (&$ranges): string {
                $metric = strtolower($m[1]);
                $op = $m[2] === ':' ? '=' : $m[2];
                $value = $this->metricNumber($m[3], $m[4] ?? null);
                if ($value === null) {
                    return ' ';
                }

                if ($op === '>' || $op === '>=') {
                    $ranges[$metric.'_min'] = $op === '>' ? $value + 1 : $value;
                } elseif ($op === '<' || $op === '<=') {
                    $ranges[$metric.'_max'] = $op === '<' ? max(0, $value - 1) : $value;
                } else { // = or :
                    $ranges[$metric.'_min'] = $value;
                    $ranges[$metric.'_max'] = $value;
                }

                return ' ';
            },
            $text
        ) ?? $text;

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return ['text' => $text, 'ranges' => $ranges];
    }

    /**
     * Merge parsed ranges into the request when the form fields are empty.
     *
     * @param  array<string, int>  $ranges
     * @return array<string, int|string>
     */
    public function mergeIntoRequestInput(string $originalSearch, string $text, array $ranges, array $input): array
    {
        $merge = [];

        if ($text !== $originalSearch) {
            $merge['search'] = $text;
        }

        foreach ($ranges as $key => $value) {
            $existing = $input[$key] ?? null;
            if ($existing === null || $existing === '') {
                $merge[$key] = $value;
            }
        }

        return $merge;
    }

    /**
     * Constrain the site query to name / category / domain matches.
     *
     * Domain/URL matching is open for all advertisers (`$searchAllDomains`
     * true from the catalog). Display masking is separate: hide-mode rows
     * still paint masked name/URL until the eye.
     *
     * @param  Collection<int, int|string>  $searchableUrlIds  Legacy allow-list
     *                                                         when `$searchAllDomains` is false.
     * @param  bool  $searchAllDomains  When true (catalog default), domain/URL
     *                                  matches are not limited to revealed rows.
     */
    public function applyTextConstraints(
        Builder $query,
        string $text,
        Collection $searchableUrlIds,
        ?string $hostNeedle = null,
        bool $searchAllDomains = true,
    ): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $like = $this->likeNeedle($text);
        $allowContains = mb_strlen($text) >= self::MIN_CONTAINS_LENGTH;

        $query->where(function (Builder $q) use ($text, $like, $allowContains, $searchableUrlIds, $hostNeedle, $searchAllDomains) {
            if ($allowContains) {
                $q->where(function (Builder $nameQ) use ($like) {
                    $nameQ->where('site_name', 'like', $like.'%')
                        ->orWhere('site_name', 'like', '% '.$like.'%')
                        ->orWhere('site_name', 'like', '%'.$like.'%');
                });

                $q->orWhere(function (Builder $catQ) use ($like) {
                    // Avoid "et" / "ket" hitting the middle of "marketing".
                    $catQ->where('category', 'like', $like.'%')
                        ->orWhere('category', 'like', '%,'.$like.'%')
                        ->orWhere('category', 'like', '%, '.$like.'%')
                        ->orWhere('category', 'like', $like)
                        ->orWhere('categories', 'like', '%"'.$like.'"%')
                        ->orWhere('categories', 'like', '%"'.$like.' %')
                        ->orWhere('categories', 'like', '%'.$like.'"%');
                });
            } else {
                // Short tokens: prefix on the listing name only.
                $q->where('site_name', 'like', $like.'%');
            }

            // Domain / URL matches — open by default so search never blocks
            // shopping. Hide-mode display still masks identity until eye.
            if ($searchAllDomains || $searchableUrlIds->isNotEmpty()) {
                $needles = array_values(array_unique(array_filter([$text, $hostNeedle])));
                if ($needles !== []) {
                    $q->orWhere(function (Builder $inner) use ($needles, $searchableUrlIds, $allowContains, $searchAllDomains) {
                        if (! $searchAllDomains) {
                            $inner->whereIn('id', $searchableUrlIds->all());
                        }

                        $inner->where(function (Builder $urlQ) use ($needles, $allowContains) {
                            foreach ($needles as $needle) {
                                $escaped = $this->likeNeedle($needle);
                                if ($allowContains || str_contains($needle, '.')) {
                                    $urlQ->orWhere('site_url', 'like', '%'.$escaped.'%')
                                        ->orWhere('domain', 'like', '%'.$escaped.'%');
                                } else {
                                    $urlQ->orWhere('domain', 'like', $escaped.'%')
                                        ->orWhere('site_url', 'like', '%'.$escaped.'%');
                                }
                            }
                        });
                    });
                }
            }
        });
    }

    /**
     * Prefer exact / prefix name hits over loose category matches.
     */
    public function applyRelevanceOrder(Builder $query, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $like = $this->likeNeedle($text);
        $lower = mb_strtolower($text);

        $query->orderByRaw(
            'CASE
                WHEN LOWER(site_name) = ? THEN 0
                WHEN LOWER(site_name) LIKE ? THEN 1
                WHEN LOWER(site_name) LIKE ? THEN 2
                WHEN LOWER(site_name) LIKE ? THEN 3
                WHEN LOWER(domain) LIKE ? OR LOWER(site_url) LIKE ? THEN 4
                WHEN LOWER(category) = ? OR LOWER(category) LIKE ? OR LOWER(category) LIKE ? THEN 5
                ELSE 6
            END ASC',
            [
                $lower,
                $like.'%',
                '% '.$like.'%',
                '%'.$like.'%',
                '%'.$like.'%',
                '%'.$like.'%',
                $lower,
                $like.'%',
                '%,'.$like.'%',
            ]
        );
    }

    private function metricNumber(string $number, ?string $suffix): ?int
    {
        if (! is_numeric($number)) {
            return null;
        }

        $value = (float) $number;
        $suffix = strtolower((string) $suffix);

        if ($suffix === 'k') {
            $value *= 1000;
        } elseif ($suffix === 'm') {
            $value *= 1000000;
        }

        if ($value < 0) {
            return null;
        }

        return (int) round($value);
    }

    private function likeNeedle(string $value): string
    {
        // Neutralize LIKE wildcards so user input cannot broaden the match.
        return str_replace(['\\', '%', '_'], ['', '', ''], $value);
    }
}
