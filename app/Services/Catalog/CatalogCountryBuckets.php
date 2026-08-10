<?php

namespace App\Services\Catalog;

/**
 * Catalog country picker ordering + fixed buyer helper groups.
 *
 * Sites are single-country; groups are multi-select shortcuts only.
 */
class CatalogCountryBuckets
{
    public const ORDER_KEYS = [
        'big_europe',
        'nordics',
        'small_europe',
        'big_english',
        'other_english',
        'other_language_markets',
        'all_other',
    ];

    public const GROUP_KEYS = [
        'dach_plus',
        'nordics',
    ];

    /**
     * @return array<string, list<string>>
     */
    public function orderBuckets(): array
    {
        $configured = config('markets.catalog_country_order', []);
        $buckets = [];

        foreach (self::ORDER_KEYS as $key) {
            $codes = $configured[$key] ?? [];
            $buckets[$key] = $this->normalizeCodes(is_array($codes) ? $codes : []);
        }

        return $this->dedupeBuckets($buckets);
    }

    /**
     * Flat ordered list of marketplace country codes (bucket 1→7).
     *
     * @return list<string>
     */
    public function orderedCodes(): array
    {
        $flat = [];
        foreach ($this->orderBuckets() as $codes) {
            foreach ($codes as $code) {
                $flat[] = $code;
            }
        }

        return $flat;
    }

    /**
     * Which order bucket owns a code (first match), or null if unknown.
     */
    public function bucketFor(string $code): ?string
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }

        foreach ($this->orderBuckets() as $key => $codes) {
            if (in_array($code, $codes, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function groups(): array
    {
        $configured = config('markets.catalog_country_groups', []);
        $groups = [];

        foreach (self::GROUP_KEYS as $key) {
            $codes = $configured[$key] ?? [];
            $groups[$key] = $this->normalizeCodes(is_array($codes) ? $codes : []);
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    public function groupCodes(string $groupKey): array
    {
        return $this->groups()[$groupKey] ?? [];
    }

    /**
     * @param  array<string, list<string>>  $buckets
     * @return array<string, list<string>>
     */
    private function dedupeBuckets(array $buckets): array
    {
        $seen = [];
        $out = [];

        foreach ($buckets as $key => $codes) {
            $unique = [];
            foreach ($codes as $code) {
                if (isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $unique[] = $code;
            }
            $out[$key] = $unique;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $codes
     * @return list<string>
     */
    private function normalizeCodes(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $normalized = strtolower(trim((string) $code));
            if ($normalized === '' || in_array($normalized, $out, true)) {
                continue;
            }
            $out[] = $normalized;
        }

        return $out;
    }
}
