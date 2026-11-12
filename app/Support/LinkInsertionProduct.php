<?php

namespace App\Support;

use App\Models\OrderItem;
use App\Models\Site;

/**
 * Link insertion as a catalog product, distinct from guest posts.
 * Publisher listing price is marked up the same way as guest posts.
 * Advertiser copy never discloses markup, advertiser counts, or order volume.
 */
final class LinkInsertionProduct
{
    public const TYPE = 'link_insertion';

    public const GUEST_POST = 'guest_post';

    /** Public marketing floor. Publishers set their own listing prices. */
    public const FROM_EUR = 15.0;

    public const PERMANENT_COPY = 'Permanent — your link stays live as long as the article remains published.';

    public const GUARANTEE_LABEL = 'Link guarantee';

    public const CONTENT_LINK = 'link-insertion';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::GUEST_POST, self::TYPE];
    }

    public static function normalize(mixed $type): string
    {
        $raw = strtolower(trim((string) $type));

        return $raw === self::TYPE ? self::TYPE : self::GUEST_POST;
    }

    public static function isLinkInsertion(mixed $value): bool
    {
        if ($value instanceof OrderItem) {
            return self::normalize($value->product_type ?? null) === self::TYPE;
        }

        if ($value instanceof Site) {
            return $value->offersLinkInsertions();
        }

        if (is_array($value)) {
            return self::normalize($value['product_type'] ?? null) === self::TYPE;
        }

        return self::normalize($value) === self::TYPE;
    }

    public static function label(mixed $type = null): string
    {
        return self::isLinkInsertion($type) ? 'Link insertion' : 'Guest post';
    }

    public static function formatFromEur(): string
    {
        if (function_exists('format_money_amount')) {
            return format_money_amount(self::FROM_EUR, 'EUR', ['decimals' => 0]);
        }

        return '€'.number_format(self::FROM_EUR, 0);
    }

    public static function marketingFromCopy(): string
    {
        return 'Link insertions from '.self::formatFromEur();
    }

    /**
     * @return list<string>
     */
    public static function siteColumns(): array
    {
        return [
            'accepts_link_insertions',
            'listing_link_insertion_price',
            'link_insertion_price',
            'link_insertion_min_article_age_days',
            'link_insertion_allow_high_traffic',
            'link_insertion_link_type',
            'link_insertion_delivery_days',
        ];
    }

    /**
     * @return list<string>
     */
    public static function orderItemColumns(): array
    {
        return [
            'product_type',
            'preferred_article_url',
            'insertion_sentence_same',
            'insertion_sentence_new',
            'insertion_sentence_new_text',
            'link_guarantee',
            'link_guarantee_fee',
            'link_guarantee_covered_amount',
        ];
    }

    public static function durationCopy(): string
    {
        return self::PERMANENT_COPY;
    }

    public static function guaranteeOptionalNote(): string
    {
        return 'Optional: add a Link guarantee at checkout (10% of the item total). '.self::PERMANENT_COPY;
    }

    public static function withoutAddonRefundCopy(): string
    {
        return 'Without the Link guarantee add-on, a full refund applies only if the publisher rejects the order. There is no refund if the live link is later removed.';
    }

    public static function withAddonPolicyCopy(): string
    {
        $days = (int) config('orders.link_guarantee.restore_days', 7);

        return 'If you add Link guarantee, SEOLinkBuildings first asks the publisher to restore a missing or broken link as early as possible (within '.$days.' days of our notice). If it cannot be restored, we refund the guaranteed placement amount to your wallet. '.self::PERMANENT_COPY;
    }

    public static function nichesCopy(): string
    {
        return 'Restricted niches (casino, adult, and the same policy list as guest posts) follow the guest-post content rules. Link insertions that break those rules are not accepted.';
    }

    public static function publishersSetPricesCopy(): string
    {
        return 'Publishers set their own link-insertion prices. Advertiser checkout shows the listing price in EUR.';
    }

    public static function faqQuestion(): string
    {
        return 'Do you sell link insertions, and how long do they stay live?';
    }

    public static function faqAnswer(): string
    {
        return 'Yes. Link insertions are a separate product from guest posts, with listings from '.self::formatFromEur().'. Publishers set their own prices. '.self::PERMANENT_COPY.' An optional 10% Link guarantee restores a missing link or refunds; without it, a full refund applies only if the publisher rejects the order. Restricted niches follow the same guest-post rules.';
    }

    public static function landerH1(): string
    {
        return 'Buy link insertions on verified publisher sites';
    }

    public static function landerSubtitle(): string
    {
        return self::marketingFromCopy().'. You choose the site, send the URL and anchor, and the publisher inserts your link into an existing article. '.self::PERMANENT_COPY;
    }

    /**
     * Optional CSV / paste column names. Blank price = not accepting.
     *
     * @return list<string>
     */
    public static function csvColumnNames(): array
    {
        return [
            'link_insertion_price',
            'accepts_link_insertions',
            'link_insertion_link_type',
            'link_insertion_delivery_days',
            'link_insertion_min_article_age_days',
            'link_insertion_allow_high_traffic',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function requestValidationRules(bool $staff = true, string $prefix = ''): array
    {
        $max = $staff ? '999999.99' : '99999999.99';
        $p = $prefix === '' ? '' : rtrim($prefix, '.').'.';

        return [
            $p.'accepts_link_insertions' => 'nullable|boolean',
            $p.'link_insertion_price' => 'nullable|numeric|min:0|max:'.$max,
            $p.'link_insertion_min_article_age_days' => 'nullable|integer|min:0|max:3650',
            $p.'link_insertion_allow_high_traffic' => 'nullable|boolean',
            $p.'link_insertion_link_type' => 'nullable|in:dofollow,nofollow',
            $p.'link_insertion_delivery_days' => 'nullable|integer|min:1|max:60',
        ];
    }

    public static function formWasPosted(array $input): bool
    {
        if (! empty($input['link_insertion_form'])) {
            return true;
        }

        foreach (self::csvColumnNames() as $column) {
            if (array_key_exists($column, $input)) {
                return true;
            }
        }

        return false;
    }

    public static function inputTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value > 0;
        }

        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * Staff and CSV amounts are EUR. Pass $listingToEuros when the posted
     * price is in the publisher listing currency.
     *
     * @param  array<string, mixed>  $input
     * @param  (callable(float): float)|null  $listingToEuros
     * @return array<string, mixed>
     */
    public static function persistenceFromInput(array $input, ?callable $listingToEuros = null): array
    {
        $available = array_values(array_filter(
            self::siteColumns(),
            static fn (string $column): bool => Site::hasSitesColumn($column)
        ));
        if ($available === []) {
            return [];
        }

        $rawPrice = $input['link_insertion_price'] ?? null;
        $priceText = is_string($rawPrice) || is_numeric($rawPrice) ? trim((string) $rawPrice) : '';
        $hasNumericPrice = $priceText !== '' && is_numeric($priceText) && (float) $priceText > 0;
        $accepts = (self::inputTruthy($input['accepts_link_insertions'] ?? null) || $hasNumericPrice)
            && $hasNumericPrice;

        $cleared = [
            'accepts_link_insertions' => false,
            'listing_link_insertion_price' => null,
            'link_insertion_price' => null,
            'link_insertion_min_article_age_days' => null,
            'link_insertion_allow_high_traffic' => false,
            'link_insertion_link_type' => 'dofollow',
            'link_insertion_delivery_days' => 7,
        ];
        if (! $accepts) {
            return array_intersect_key($cleared, array_flip($available));
        }

        $listing = round((float) $priceText, 2);
        $euros = $listingToEuros !== null
            ? round((float) $listingToEuros($listing), 2)
            : $listing;

        $minAge = $input['link_insertion_min_article_age_days'] ?? null;
        $minAgeText = is_string($minAge) || is_numeric($minAge) ? trim((string) $minAge) : '';
        $delivery = (int) ($input['link_insertion_delivery_days'] ?? 7);
        if ($delivery < 1) {
            $delivery = 7;
        }
        if ($delivery > 60) {
            $delivery = 60;
        }

        $payload = [
            'accepts_link_insertions' => true,
            'listing_link_insertion_price' => $listing,
            'link_insertion_price' => $euros,
            'link_insertion_min_article_age_days' => ($minAgeText !== '' && is_numeric($minAgeText))
                ? max(0, min(3650, (int) $minAgeText))
                : null,
            'link_insertion_allow_high_traffic' => self::inputTruthy($input['link_insertion_allow_high_traffic'] ?? null),
            'link_insertion_link_type' => strtolower(trim((string) ($input['link_insertion_link_type'] ?? 'dofollow'))) === 'nofollow'
                ? 'nofollow'
                : 'dofollow',
            'link_insertion_delivery_days' => $delivery,
        ];

        return array_intersect_key($payload, array_flip($available));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function csvErrors(array $data): array
    {
        $errors = [];
        $price = trim((string) ($data['link_insertion_price'] ?? ''));
        if ($price !== '' && (! is_numeric($price) || (float) $price < 0 || (float) $price > 999999.99)) {
            $errors[] = 'link_insertion_price must be a number between 0 and 999999.99.';
        }

        $type = strtolower(trim((string) ($data['link_insertion_link_type'] ?? '')));
        if ($type !== '' && ! in_array($type, ['dofollow', 'nofollow'], true)) {
            $errors[] = 'link_insertion_link_type must be dofollow or nofollow.';
        }

        $days = trim((string) ($data['link_insertion_delivery_days'] ?? ''));
        if ($days !== '' && (! ctype_digit($days) || (int) $days < 1 || (int) $days > 60)) {
            $errors[] = 'link_insertion_delivery_days must be a whole number from 1 to 60.';
        }

        $age = trim((string) ($data['link_insertion_min_article_age_days'] ?? ''));
        if ($age !== '' && (! ctype_digit($age) || (int) $age < 0 || (int) $age > 3650)) {
            $errors[] = 'link_insertion_min_article_age_days must be a whole number from 0 to 3650.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, string>
     */
    public static function csvInputFromMapped(array $mapped): array
    {
        $out = [];
        foreach (self::csvColumnNames() as $column) {
            if (array_key_exists($column, $mapped)) {
                $out[$column] = trim((string) $mapped[$column]);
            }
        }

        return $out;
    }

    /**
     * Optional LI columns after the 15 positional site columns.
     *
     * @param  list<mixed>  $tail
     * @return array<string, string>
     */
    public static function csvInputFromPositionalTail(array $tail): array
    {
        $names = self::csvColumnNames();
        $out = [];
        foreach (array_values($tail) as $index => $value) {
            if (! isset($names[$index])) {
                break;
            }
            $out[$names[$index]] = trim((string) $value);
        }

        return $out;
    }

    /**
     * Staff list / export: publisher EUR amount, never advertiser markup.
     */
    public static function staffListPrice(?Site $site): ?float
    {
        if (! $site instanceof Site || ! $site->offersLinkInsertions()) {
            return null;
        }

        $amount = $site->listingLinkInsertionAmount();
        if ($amount <= 0) {
            $amount = $site->publisherLinkInsertionPrice();
        }

        return $amount > 0 ? round($amount, 2) : null;
    }
}
