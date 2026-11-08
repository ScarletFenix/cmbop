<?php

namespace App\Services\WeeklyNewSites;

use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Site;
use App\Models\User;
use App\Models\WeeklyNewSitesInterest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Exact niche+country pairs from paid, non-cancelled, non-refunded placements.
 *
 * A Finance/UK buy plus a Tech/US buy yields those two pairs — never the
 * cross-product (Finance/US, Tech/UK). Managed-by-SLB lines count: they are
 * still the advertiser's paid placement on that listing.
 */
class InterestSync
{
    /**
     * @return Collection<int, WeeklyNewSitesInterest>
     */
    public function enabledPairs(User $user): Collection
    {
        if (! WeeklyNewSitesInterest::tableReady()) {
            return collect();
        }

        $this->syncInferred($user);

        return WeeklyNewSitesInterest::query()
            ->where('user_id', $user->id)
            ->where('enabled', true)
            ->orderBy('niche')
            ->orderBy('country_code')
            ->get();
    }

    public function syncInferred(User $user): void
    {
        if (! WeeklyNewSitesInterest::tableReady()) {
            return;
        }

        foreach ($this->inferredPairs($user) as $pair) {
            $existing = WeeklyNewSitesInterest::query()
                ->where('user_id', $user->id)
                ->where('niche', $pair['niche'])
                ->where('country_code', $pair['country_code'])
                ->first();

            if ($existing) {
                continue;
            }

            WeeklyNewSitesInterest::query()->create([
                'user_id' => $user->id,
                'niche' => $pair['niche'],
                'country_code' => $pair['country_code'],
                'source' => WeeklyNewSitesInterest::SOURCE_INFERRED,
                'enabled' => true,
            ]);
        }
    }

    /**
     * @return list<array{niche: string, country_code: string}>
     */
    public function inferredPairs(User $user): array
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return [];
        }

        $itemQuery = OrderItem::query()
            ->whereHas('order', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->whereIn('payment_status', ['paid', 'completed'])
                    ->where(function ($status) {
                        $status->whereNull('status')
                            ->orWhereNotIn('status', ['cancelled', 'canceled']);
                    });
                if (Schema::hasColumn('orders', 'is_sandbox')) {
                    $q->where(function ($inner) {
                        $inner->whereNull('is_sandbox')->orWhere('is_sandbox', 0);
                    });
                }
            })
            ->with('site');

        if (Schema::hasTable('order_item_disputes')) {
            $itemQuery->whereDoesntHave('disputes', function ($disputes) {
                $disputes->where('status', OrderItemDispute::STATUS_UPHELD);
            });
        }

        $seen = [];
        $pairs = [];
        foreach ($itemQuery->get() as $item) {
            $site = $item->site;
            if (! $site instanceof Site) {
                continue;
            }
            foreach ($this->pairsFromSite($site) as $pair) {
                $key = strtolower($pair['niche']).'|'.$pair['country_code'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * @return list<array{niche: string, country_code: string}>
     */
    public function pairsFromSite(Site $site): array
    {
        $country = strtolower(trim((string) ($site->primaryCountryCode() ?? '')));
        if ($country === '') {
            return [];
        }

        $niches = $site->nicheBadgeLabels();
        if ($niches === []) {
            $legacy = trim((string) ($site->category ?? ''));
            if ($legacy !== '') {
                $niches = [$legacy];
            }
        }

        $pairs = [];
        $seen = [];
        foreach ($niches as $niche) {
            $niche = trim((string) $niche);
            if ($niche === '') {
                continue;
            }
            $key = strtolower($niche).'|'.$country;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pairs[] = ['niche' => $niche, 'country_code' => $country];
        }

        return $pairs;
    }

    public function upsertManual(User $user, string $niche, string $countryCode, bool $enabled = true): WeeklyNewSitesInterest
    {
        $niche = trim($niche);
        $countryCode = strtolower(trim($countryCode));

        return WeeklyNewSitesInterest::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'niche' => $niche,
                'country_code' => $countryCode,
            ],
            [
                'source' => WeeklyNewSitesInterest::SOURCE_MANUAL,
                'enabled' => $enabled,
            ]
        );
    }
}
