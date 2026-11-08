<?php

namespace App\Services\WeeklyNewSites;

use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\WeeklyNewSitesInterest;
use App\Models\WeeklyNewSitesSend;
use App\Services\CartPricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Eligible newly catalogued sites for one advertiser's enabled pairs.
 *
 * Carry-forward is implicit: sites are only recorded as sent when an email
 * actually includes them. A thin week (0 or 1 match) leaves them for later.
 */
class MatchSelector
{
    public function __construct(
        private Watermark $watermark,
        private InterestSync $interests,
        private CartPricingService $pricing,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function matches(User $user, ?int $limit = null): Collection
    {
        $pairs = $this->interests->enabledPairs($user);
        if ($pairs->isEmpty()) {
            return collect();
        }

        $activated = $this->watermark->establish();
        $candidates = $this->candidateSites($pairs, $activated);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $orderedSiteIds = $this->orderedSiteIds($user);
        $orderedDomains = $this->orderedDomains($user);
        $sentSiteIds = $this->sentSiteIds($user);
        $sentDomains = $this->sentDomains($user);

        $seenDomains = [];
        $rows = collect();

        foreach ($candidates as $site) {
            if (! $site instanceof Site || ! $site->isCatalogVisible()) {
                continue;
            }
            if (in_array((int) $site->id, $orderedSiteIds, true)
                || in_array((int) $site->id, $sentSiteIds, true)) {
                continue;
            }

            $domain = $this->normalizedDomain($site);
            if ($domain === '' || isset($seenDomains[$domain])
                || isset($orderedDomains[$domain])
                || isset($sentDomains[$domain])) {
                continue;
            }

            $matched = $this->matchingPairs($site, $pairs);
            if ($matched === []) {
                continue;
            }

            $card = $this->card($site, $matched[0], $user);
            if ($card === null) {
                continue;
            }

            $seenDomains[$domain] = true;
            $rows->push($card);
        }

        $max = $limit ?? max(1, (int) config('weekly_new_sites_digest.max_sites', 5));

        return $rows->take($max)->values();
    }

    /**
     * Recheck a previously selected card list at dispatch. Drops sites that
     * are no longer public, priced, or matching. Does not invent replacements
     * here — the caller decides whether the remaining set still meets min.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return Collection<int, array<string, mixed>>
     */
    public function recheck(User $user, Collection $cards): Collection
    {
        $pairs = $this->interests->enabledPairs($user);
        $fresh = collect();

        foreach ($cards as $card) {
            $siteId = (int) ($card['site_id'] ?? 0);
            if ($siteId < 1) {
                continue;
            }
            $site = Site::query()->find($siteId);
            if (! $site instanceof Site || ! $site->isCatalogVisible()) {
                continue;
            }
            $matched = $this->matchingPairs($site, $pairs);
            if ($matched === []) {
                continue;
            }
            $rebuilt = $this->card($site, $matched[0], $user);
            if ($rebuilt === null) {
                continue;
            }
            $fresh->push($rebuilt);
        }

        return $fresh->values();
    }

    /**
     * @param  Collection<int, WeeklyNewSitesInterest>  $pairs
     * @return Collection<int, Site>
     */
    private function candidateSites(Collection $pairs, Carbon $activated): Collection
    {
        if (! Schema::hasColumn('sites', 'first_catalogued_at')) {
            return collect();
        }

        $query = Site::query()->catalogVisible()->whereNotNull('first_catalogued_at');

        $query->where('first_catalogued_at', '>', $activated)
            ->where('first_catalogued_at', '<=', now());

        $query->where(function ($outer) use ($pairs) {
            foreach ($pairs as $pair) {
                $niche = trim((string) $pair->niche);
                $country = strtolower(trim((string) $pair->country_code));
                if ($niche === '' || $country === '') {
                    continue;
                }
                $outer->orWhere(function ($inner) use ($niche, $country) {
                    $inner->whereRaw('LOWER(country) = ?', [$country]);
                    $inner->where(function ($nicheQuery) use ($niche) {
                        $nicheQuery->whereRaw('LOWER(category) = ?', [strtolower($niche)]);
                        if (Schema::hasColumn('sites', 'categories')) {
                            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $niche);
                            $nicheQuery->orWhereRaw(
                                'LOWER(CAST(categories AS CHAR)) LIKE ?',
                                ['%'.strtolower($escaped).'%']
                            );
                        }
                    });
                });
            }
        });

        return $query
            ->orderBy('first_catalogued_at')
            ->orderBy('id')
            ->limit(400)
            ->get()
            ->filter(function (Site $site) use ($activated) {
                $first = $site->first_catalogued_at;
                if (! $first instanceof \DateTimeInterface) {
                    return false;
                }

                return Carbon::instance($first)->greaterThan($activated);
            })
            ->values();
    }

    /**
     * @param  Collection<int, WeeklyNewSitesInterest>  $pairs
     * @return list<array{niche: string, country_code: string}>
     */
    private function matchingPairs(Site $site, Collection $pairs): array
    {
        $country = strtolower(trim((string) ($site->primaryCountryCode() ?? '')));
        if ($country === '') {
            return [];
        }

        $labels = array_map(
            static fn (string $n) => strtolower(trim($n)),
            $site->nicheBadgeLabels()
        );
        $legacy = strtolower(trim((string) ($site->category ?? '')));
        if ($legacy !== '') {
            $labels[] = $legacy;
        }
        $labels = array_values(array_unique(array_filter($labels)));

        $matched = [];
        foreach ($pairs as $pair) {
            $pairCountry = strtolower(trim((string) $pair->country_code));
            $pairNiche = strtolower(trim((string) $pair->niche));
            if ($pairCountry !== $country || $pairNiche === '') {
                continue;
            }
            if (! in_array($pairNiche, $labels, true)) {
                continue;
            }
            $matched[] = [
                'niche' => (string) $pair->niche,
                'country_code' => $pairCountry,
            ];
        }

        return $matched;
    }

    /**
     * @param  array{niche: string, country_code: string}  $pair
     * @return array<string, mixed>|null
     */
    public function card(Site $site, array $pair, ?User $user = null): ?array
    {
        try {
            $priced = $this->pricing->priceForAdvertiser($site);
        } catch (\Throwable) {
            return null;
        }

        $price = isset($priced['total']) ? (float) $priced['total'] : 0.0;
        if ($price <= 0) {
            return null;
        }

        $countryCode = $pair['country_code'];
        $countryLabel = $this->countryLabel($countryCode);
        $da = $this->presentInt($site->da ?? null);
        $dr = $this->presentInt($site->dr ?? null);
        $traffic = $this->presentInt($site->traffic ?? null);
        $metricsAt = $site->metrics_fetched_at instanceof \DateTimeInterface
            ? Carbon::instance($site->metrics_fetched_at)
            : null;

        return [
            'site_id' => (int) $site->id,
            'domain' => $this->normalizedDomain($site) ?: (string) ($site->domain ?? ''),
            'niche' => $pair['niche'],
            'country_code' => $countryCode,
            'country_label' => $countryLabel,
            'da' => $da,
            'dr' => $dr,
            'traffic' => $traffic,
            'metrics_checked_at' => $metricsAt?->toDateString(),
            'price' => $price,
            'screenshot_url' => null,
            'has_screenshot' => false,
            'site' => $site,
        ];
    }

    /**
     * Distinct match pairs in a card set, in first-seen order.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return list<array{niche: string, country_code: string, country_label: string}>
     */
    public function distinctPairs(Collection $cards): array
    {
        $out = [];
        $seen = [];
        foreach ($cards as $card) {
            $niche = trim((string) ($card['niche'] ?? ''));
            $country = strtolower(trim((string) ($card['country_code'] ?? '')));
            if ($niche === '' || $country === '') {
                continue;
            }
            $key = strtolower($niche).'|'.$country;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'niche' => $niche,
                'country_code' => $country,
                'country_label' => (string) ($card['country_label'] ?? $this->countryLabel($country)),
            ];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public function orderedSiteIds(User $user): array
    {
        if (! Schema::hasTable('order_items')) {
            return [];
        }

        return OrderItem::query()
            ->whereHas('order', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->whereIn('payment_status', ['paid', 'completed', 'refunded']);
            })
            ->pluck('site_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, true>
     */
    public function orderedDomains(User $user): array
    {
        $ids = $this->orderedSiteIds($user);
        if ($ids === []) {
            return [];
        }

        $map = [];
        Site::query()->whereIn('id', $ids)->get(['id', 'domain', 'site_url'])->each(function (Site $site) use (&$map) {
            $domain = $this->normalizedDomain($site);
            if ($domain !== '') {
                $map[$domain] = true;
            }
        });

        return $map;
    }

    /**
     * @return list<int>
     */
    public function sentSiteIds(User $user): array
    {
        if (! WeeklyNewSitesSend::tableReady()) {
            return [];
        }

        return WeeklyNewSitesSend::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [WeeklyNewSitesSend::STATUS_QUEUED, WeeklyNewSitesSend::STATUS_SENT])
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, true>
     */
    public function sentDomains(User $user): array
    {
        if (! WeeklyNewSitesSend::tableReady()) {
            return [];
        }

        $map = [];
        WeeklyNewSitesSend::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [WeeklyNewSitesSend::STATUS_QUEUED, WeeklyNewSitesSend::STATUS_SENT])
            ->whereNotNull('domain')
            ->pluck('domain')
            ->each(function ($domain) use (&$map) {
                $normalized = Site::normalizeMarketplaceDomain((string) $domain);
                if ($normalized !== '') {
                    $map[$normalized] = true;
                }
            });

        return $map;
    }

    public function normalizedDomain(Site $site): string
    {
        $raw = trim((string) ($site->domain ?? ''));
        if ($raw === '') {
            $host = parse_url((string) ($site->site_url ?? ''), PHP_URL_HOST);
            $raw = is_string($host) ? $host : '';
        }

        return Site::normalizeMarketplaceDomain($raw);
    }

    private function countryLabel(string $code): string
    {
        $code = strtolower(trim($code));
        $known = function_exists('marketplace_countries') ? marketplace_countries() : [];

        return is_string($known[$code] ?? null) && $known[$code] !== ''
            ? $known[$code]
            : strtoupper($code);
    }

    private function presentInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
