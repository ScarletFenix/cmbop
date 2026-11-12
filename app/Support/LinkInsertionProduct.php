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
}
