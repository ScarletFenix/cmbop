<?php

namespace App\Services;

use App\Models\Site;

class CartPricingService
{
    /**
     * @deprecated Use PlatformFeeService tiered fees. Kept for legacy call sites / tests.
     */
    public const PLATFORM_MARKUP_RATE = 1.15;

    private readonly PlatformFeeService $fees;

    public function __construct(?PlatformFeeService $fees = null)
    {
        $this->fees = $fees ?? new PlatformFeeService;
    }

    /**
     * Resolve advertiser unit pricing from the live site listing.
     * Never trust client-supplied price / additional_price values.
     *
     * Discounts are exclusive better-of (never additive): the stronger of an
     * active custom sale and a bulk pack rate (qty 3–5) applies. The cut is
     * absorbed by the platform fee and floored at publisher payout.
     *
     * `discount_percent` is the effective rate after that floor (what the
     * advertiser actually saves). `discount_percent_nominal` is the configured
     * better-of % before flooring — use that only when re-applying the offer
     * math (catalog JS), not on chips / checkout labels.
     *
     * @return array{
     *   base: float,
     *   additional: float,
     *   total: float,
     *   sensitive_type: ?string,
     *   list_total: float,
     *   discount_percent: float,
     *   discount_percent_nominal: float,
     *   discount_amount: float,
     *   discount_labels: array<int, string>,
     *   publisher_price: float,
     *   platform_fee_percent: float,
     *   platform_fee_amount: float
     * }
     */
    public function priceForAdvertiser(Site $site, ?string $sensitiveType = null, int $quantity = 1): array
    {
        $publisherPrice = round((float) $site->price, 2);
        $feePercent = $this->fees->feePercentForBase($publisherPrice);
        $feeAmount = $this->fees->feeAmountForBase($publisherPrice);
        $base = $this->fees->advertiserBase($publisherPrice);
        $additional = $this->resolveSensitiveAdditional($site, $sensitiveType);

        // Canonicalize type to the key stored on the site (e.g. CBD not cbd).
        $canonicalType = null;
        if ($sensitiveType !== null && $sensitiveType !== '' && $additional > 0) {
            $prices = $site->sensitive_prices ?? [];
            if (is_string($prices)) {
                $prices = json_decode($prices, true) ?: [];
            }
            if (is_array($prices)) {
                $canonicalType = $this->resolveSensitiveTypeKey($prices, $sensitiveType);
            }
        }

        $listTotal = round($base + $additional, 2);

        $nominalPercent = 0.0;
        $winner = null; // 'custom' | 'bulk' | null

        $custom = $site->activeCustomDiscountPercent();
        if ($custom !== null) {
            $nominalPercent = max($nominalPercent, (float) $custom);
            $winner = 'custom';
        }

        $bulkPercent = $this->bulkDiscountPercentForQuantity($site, $quantity);
        if ($bulkPercent !== null) {
            // Better-of: take the stronger of custom vs bulk (never additive).
            if ($bulkPercent > $nominalPercent) {
                $nominalPercent = $bulkPercent;
                $winner = 'bulk';
            } elseif ($bulkPercent == $nominalPercent && $custom === null) {
                $winner = 'bulk';
            }
            // When bulk ≤ custom, custom already winning — keep custom.
        }

        $discountAmount = round($listTotal * ($nominalPercent / 100), 2);
        $total = max(0, round($listTotal - $discountAmount, 2));

        // Publisher payout is the entered base + sensitive add-on (never cut by
        // advertiser-facing discounts). Discounts are absorbed by the platform fee
        // only — never let the advertiser pay less than the publisher will receive.
        $publisherPayout = round($publisherPrice + $additional, 2);
        if ($total < $publisherPayout) {
            $total = $publisherPayout;
            $discountAmount = max(0, round($listTotal - $total, 2));
        }

        // Advertiser-facing % must match real savings after the floor.
        $effectivePercent = self::effectiveDiscountPercent($listTotal, $discountAmount);
        $labels = [];
        if ($effectivePercent > 0 && $winner === 'bulk') {
            $labels[] = 'Bulk deal −'.$this->formatDiscountPercent($effectivePercent).'% on '.$quantity.' articles';
        } elseif ($effectivePercent > 0) {
            $labels[] = 'Site offer −'.$this->formatDiscountPercent($effectivePercent).'%';
        }

        // Fee retained on this unit after discount (may be €0 when discount eats the fee).
        $feeAmount = max(0, round($total - $publisherPayout, 2));

        return [
            'base' => $base,
            'additional' => $additional,
            'list_total' => $listTotal,
            'total' => $total,
            'sensitive_type' => $additional > 0 ? ($canonicalType ?: $sensitiveType) : null,
            'discount_percent' => $effectivePercent,
            'discount_percent_nominal' => $nominalPercent,
            'discount_amount' => $discountAmount,
            'discount_labels' => $labels,
            'publisher_price' => $publisherPrice,
            'platform_fee_percent' => $feePercent,
            'platform_fee_amount' => $feeAmount,
        ];
    }

    /**
     * Percent of list total actually saved (after publisher-payout floor).
     */
    public static function effectiveDiscountPercent(float $listTotal, float $discountAmount): float
    {
        if ($listTotal <= 0 || $discountAmount <= 0) {
            return 0.0;
        }

        return round(($discountAmount / $listTotal) * 100, 2);
    }

    private function formatDiscountPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2), '0'), '.');
    }

    public function bulkDiscountPercentForQuantity(Site $site, int $quantity): ?float
    {
        if (! $site->joinsBulkDiscount()) {
            return null;
        }

        $min = (int) config('site_promotions.bulk.min_qty', 3);
        $max = (int) config('site_promotions.bulk.max_qty', 5);
        if ($quantity < $min || $quantity > $max) {
            return null;
        }

        return (float) $site->bulk_discount_percent;
    }

    /**
     * Look up the sensitive add-on from the site's configured prices.
     *
     * @throws \InvalidArgumentException when the type is not offered by the site
     */
    public function resolveSensitiveAdditional(Site $site, ?string $sensitiveType): float
    {
        if ($sensitiveType === null || $sensitiveType === '') {
            return 0.0;
        }

        $prices = $site->sensitive_prices ?? [];
        if (is_string($prices)) {
            $prices = json_decode($prices, true) ?: [];
        }

        if (! is_array($prices)) {
            throw new \InvalidArgumentException(
                'Invalid or unavailable sensitive content type for site: '.$site->site_name
            );
        }

        $resolvedKey = $this->resolveSensitiveTypeKey($prices, $sensitiveType);
        if ($resolvedKey === null) {
            throw new \InvalidArgumentException(
                'Invalid or unavailable sensitive content type for site: '.$site->site_name
            );
        }

        return round((float) $prices[$resolvedKey], 2);
    }

    /**
     * Match a requested sensitive type to the site's configured key (case-insensitive).
     * Publishers store "CBD"; clients may send "cbd".
     *
     * @param  array<string, mixed>  $prices
     */
    public function resolveSensitiveTypeKey(array $prices, string $sensitiveType): ?string
    {
        if (array_key_exists($sensitiveType, $prices)) {
            return $sensitiveType;
        }

        $needle = strtolower(trim($sensitiveType));
        foreach ($prices as $key => $amount) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }
            if (strtolower((string) $key) === $needle && is_numeric($amount) && (float) $amount > 0) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Expand a session cart into per-unit line items with server-calculated prices.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array<string, mixed>>
     *
     * @throws \Exception
     */
    public function expandCart(array $cart): array
    {
        $expanded = [];

        foreach ($cart as $item) {
            $siteId = $item['id'] ?? null;
            $site = Site::where('id', $siteId)->where('active', 1)->first();

            if (! $site) {
                throw new \Exception(
                    'Site not found or inactive: '.($item['name'] ?? $siteId ?? 'unknown')
                );
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $pricing = $this->priceForAdvertiser($site, $item['sensitive_type'] ?? null, $quantity);

            for ($i = 0; $i < $quantity; $i++) {
                $expanded[] = [
                    'site' => $site,
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => $pricing['total'],
                    'base_price' => $pricing['base'],
                    'additional_price' => $pricing['additional'],
                    'sensitive_type' => $pricing['sensitive_type'],
                    'copy_number' => $i + 1,
                    'list_total' => $pricing['list_total'],
                    'discount_percent' => $pricing['discount_percent'],
                    'discount_amount' => $pricing['discount_amount'],
                    'discount_labels' => $pricing['discount_labels'],
                    'publisher_price' => $pricing['publisher_price'],
                    'platform_fee_percent' => $pricing['platform_fee_percent'],
                    'platform_fee_amount' => $pricing['platform_fee_amount'],
                ];
            }
        }

        return $expanded;
    }

    /**
     * Build checkout display rows from the session cart using DB prices.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array{items: array<int, array<string, mixed>>, total: float, savings: float}
     */
    public function buildCheckoutItems(array $cart): array
    {
        $items = [];
        $total = 0.0;
        $savings = 0.0;

        foreach ($cart as $item) {
            $site = Site::where('id', $item['id'] ?? null)->where('active', 1)->first();
            if (! $site) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $pricing = $this->priceForAdvertiser($site, $item['sensitive_type'] ?? null, $quantity);
            $lineTotal = round($pricing['total'] * $quantity, 2);
            $lineList = round($pricing['list_total'] * $quantity, 2);
            $lineSave = round(max(0, $lineList - $lineTotal), 2);
            $total += $lineTotal;
            $savings += $lineSave;

            // Preserve per-placement article slots (bulk packs qty 3–5). Dropping
            // content_submission_ids left checkout with only a scalar id and lost
            // assignments for copies 2…N.
            $slotIds = $this->normalizeContentSubmissionIds($item, $quantity);

            $items[] = [
                'id' => $site->id,
                'name' => $site->site_name,
                'url' => $site->site_url,
                'price' => $pricing['total'],
                'base_price' => $pricing['base'],
                'additional_price' => $pricing['additional'],
                'sensitive_type' => $pricing['sensitive_type'],
                'quantity' => $quantity,
                'total' => $lineTotal,
                'list_total' => $pricing['list_total'],
                'line_list_total' => $lineList,
                'discount_percent' => $pricing['discount_percent'],
                'discount_amount' => $pricing['discount_amount'],
                'line_savings' => $lineSave,
                'discount_labels' => $pricing['discount_labels'],
                'publisher_price' => $pricing['publisher_price'],
                'platform_fee_percent' => $pricing['platform_fee_percent'],
                'platform_fee_amount' => $pricing['platform_fee_amount'],
                'country' => $site->country,
                'countries' => $site->countryCodes(),
                'language' => $site->language,
                'languages' => $site->languageCodes(),
                'link_type' => $site->link_type,
                'content_submission_id' => ($slotIds[0] ?? 0) > 0 ? $slotIds[0] : null,
                'content_submission_ids' => $slotIds,
                'bulk_eligible' => $site->joinsBulkDiscount(),
                'featured' => $site->isFeatured(),
            ];
        }

        return [
            'items' => $items,
            'total' => round($total, 2),
            'savings' => round($savings, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, int>
     */
    private function normalizeContentSubmissionIds(array $item, int $quantity): array
    {
        $quantity = max(1, $quantity);
        $raw = is_array($item['content_submission_ids'] ?? null)
            ? $item['content_submission_ids']
            : [];
        $legacy = (int) ($item['content_submission_id'] ?? 0);
        $normalized = [];

        for ($i = 0; $i < $quantity; $i++) {
            $id = (int) ($raw[$i] ?? 0);
            if ($id <= 0 && $i === 0 && $legacy > 0) {
                $id = $legacy;
            }
            $normalized[$i] = max(0, $id);
        }

        return $normalized;
    }
}
