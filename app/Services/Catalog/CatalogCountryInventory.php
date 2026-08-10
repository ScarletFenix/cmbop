<?php

namespace App\Services\Catalog;

use App\Models\Country;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Active-site inventory counts per marketplace country (one country per site).
 */
class CatalogCountryInventory
{
    public const CACHE_KEY = 'catalog.country_inventory';

    public const CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly CatalogCountryBuckets $buckets = new CatalogCountryBuckets,
    ) {}

    /**
     * @return array<string, int> code => active site count
     */
    public function counts(): array
    {
        /** @var array<string, int> $counts */
        $counts = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return $this->computeCounts();
        });

        return $counts;
    }

    /**
     * Marketplace countries with inventory metadata for the catalog picker.
     *
     * @param  bool  $onlyWithInventory  When true, drop zero-count markets
     * @return list<array{code: string, name: string, count: int}>
     */
    public function options(bool $onlyWithInventory = true): array
    {
        $counts = $this->counts();
        $names = Country::marketplace()
            ->orderBy('name')
            ->pluck('name', 'code')
            ->mapWithKeys(fn ($name, $code) => [strtolower((string) $code) => (string) $name])
            ->all();

        $options = [];
        $seen = [];
        foreach ($this->buckets->orderedCodes() as $code) {
            $count = (int) ($counts[$code] ?? 0);
            if ($onlyWithInventory && $count < 1) {
                continue;
            }

            $options[] = [
                'code' => $code,
                'name' => $names[$code] ?? strtoupper($code),
                'count' => $count,
            ];
            $seen[$code] = true;
        }

        // Any allowlisted active count not already in the ordered list (should be rare).
        foreach ($counts as $code => $count) {
            if ($count < 1 || isset($seen[$code])) {
                continue;
            }

            $options[] = [
                'code' => $code,
                'name' => $names[$code] ?? strtoupper($code),
                'count' => (int) $count,
            ];
        }

        return $options;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Primary country for inventory counting: single-country rule.
     */
    public function primaryCountryCode(?string $country, mixed $countries): ?string
    {
        $code = strtolower(trim((string) ($country ?? '')));
        if ($code !== '') {
            return $code;
        }

        $list = is_array($countries) ? $countries : [];
        foreach ($list as $item) {
            $normalized = strtolower(trim((string) $item));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function computeCounts(): array
    {
        $allow = array_fill_keys(
            array_map('strtolower', config('markets.allowed_country_codes', [])),
            true
        );

        $counts = [];

        Site::query()
            ->where('active', 1)
            ->select(['id', 'country', 'countries'])
            ->orderBy('id')
            ->chunkById(500, function ($sites) use (&$counts, $allow) {
                foreach ($sites as $site) {
                    $code = $this->primaryCountryCode($site->country, $site->countries);
                    if ($code === null || ! isset($allow[$code])) {
                        continue;
                    }
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            });

        return $counts;
    }
}
