<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Shared mapping for transactional mail: human status labels, timezone stamps,
 * placement + live-URL rows, and audience-safe summary fields.
 *
 * Notification summary tables never include counterparty names. Live article
 * URLs are shown only when a real http(s) value exists and the context is
 * review/completed — pending placements are never labelled live.
 */
class MailNotificationPresenter
{
    public const CONTEXT_REVIEW_READY = 'review_ready';

    public const CONTEXT_STATUS = 'status';

    public const CONTEXT_PAYMENT = 'payment';

    public const CONTEXT_CREATED = 'created';

    public const CONTEXT_COMPLETED = 'completed';

    public const CONTEXT_RECEIPT = 'receipt';

    public static function orderStatusLabel(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'pending' => 'Pending',
            'processing', 'in_progress' => 'Processing',
            'review', 'in_review' => 'In review',
            'completed' => 'Completed',
            'cancelled', 'canceled' => 'Cancelled',
            'scheduled' => 'Scheduled',
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function paymentStatusLabel(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'paid', 'completed' => 'Paid',
            'pending' => 'Pending',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
            'unpaid' => 'Unpaid',
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function timezone(): string
    {
        if (app()->bound('transactional.mailTimezone')) {
            $bound = trim((string) app('transactional.mailTimezone'));
            if ($bound !== '') {
                return $bound;
            }
        }

        $tz = trim((string) config('app.timezone', 'UTC'));

        return $tz !== '' ? $tz : 'UTC';
    }

    /**
     * Prefer a timezone stored on the recipient, then the order schedule TZ, else UTC.
     */
    public static function bindMailTimezone(mixed $user = null, mixed $order = null): string
    {
        $tz = static::resolveMailTimezone($user, $order);
        app()->instance('transactional.mailTimezone', $tz);

        return $tz;
    }

    public static function resolveMailTimezone(mixed $user = null, mixed $order = null): string
    {
        $candidates = [];
        if ($user instanceof User) {
            foreach (['timezone', 'display_timezone', 'time_zone', 'tz'] as $key) {
                $candidates[] = $user->getAttribute($key);
            }
        }
        if (is_object($order)) {
            $candidates[] = $order->schedule_timezone ?? null;
        }

        foreach ($candidates as $candidate) {
            $tz = trim((string) $candidate);
            if ($tz === '') {
                continue;
            }
            try {
                new \DateTimeZone($tz);

                return $tz;
            } catch (\Throwable) {
                continue;
            }
        }

        return 'UTC';
    }

    public static function formatDateTime(mixed $value, mixed $timezoneOrUser = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $tz = static::timezone();
        if ($timezoneOrUser instanceof User) {
            $tz = static::resolveMailTimezone($timezoneOrUser);
        } elseif (is_string($timezoneOrUser) && trim($timezoneOrUser) !== '') {
            $tz = trim($timezoneOrUser);
        }

        try {
            $date = $value instanceof CarbonInterface
                ? $value->copy()
                : Carbon::parse((string) $value);

            $local = $date->timezone($tz);

            return $local->format('j M Y, H:i T');
        } catch (\Throwable) {
            return '';
        }
    }

    public static function displayUrl(?string $url): string
    {
        $safe = static::safeLiveUrl($url);
        if (! is_string($safe) || $safe === '') {
            return '';
        }

        return (string) preg_replace('~^https?://~i', '', $safe);
    }

    public static function displayUrlTruncated(?string $url, int $max = 42): string
    {
        $display = static::displayUrl($url);
        if ($display === '' || mb_strlen($display) <= $max) {
            return $display;
        }

        return rtrim(mb_substr($display, 0, max(1, $max - 1)), './-').'…';
    }

    public static function bareHost(mixed $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $prefixed = preg_match('~^https?://~i', $text) === 1 ? $text : 'https://'.$text;
        $host = parse_url($prefixed, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return $host !== '' ? $host : null;
    }

    public static function hasMailValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }

        $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text !== ''
            && $text !== '—'
            && $text !== '-'
            && strcasecmp($text, 'n/a') !== 0
            && strcasecmp($text, 'not submitted yet') !== 0;
    }

    /**
     * @param  list<array{label?: string, value?: mixed, url?: mixed}>  $rows
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function filledMailRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $value = $row['value'] ?? '';
            $url = $row['url'] ?? null;
            if (! static::hasMailValue($value) && ! static::hasMailValue($url)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    public static function advertiserFacingItemPrice(OrderItem $item): float
    {
        return $item->advertiserEurAmount();
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function orderPlacedDetailRows(Order $order, mixed $totalAmount = null): array
    {
        static::bindMailTimezone($order->user ?? null, $order);
        $total = $totalAmount !== null ? (float) $totalAmount : (float) $order->total_amount;
        $method = Invoice::paymentMethodLabel($order->payment_method);
        $orderDate = static::formatDateTime($order->created_at);
        $paidDate = static::formatDateTime($order->paid_at ?: now());
        $dateRows = $orderDate !== '' && $orderDate === $paidDate
            ? [['label' => 'Paid on', 'value' => $paidDate]]
            : [
                ['label' => 'Order date', 'value' => $orderDate],
                ['label' => 'Payment date', 'value' => $paidDate],
            ];

        $order->loadMissing('items');
        $item = $order->items->first();
        $productRow = [];
        $guaranteeRow = [];
        if ($item instanceof OrderItem && $item->isLinkInsertion()) {
            $productRow[] = ['label' => 'Product', 'value' => 'Link insertion'];
            $fee = round((float) ($item->link_guarantee_fee ?? 0), 2);
            if ($item->hasPaidLinkGuarantee()) {
                $guaranteeRow[] = [
                    'label' => 'Link guarantee',
                    'value' => $fee > 0
                        ? 'Permanent add-on ('.static::euro($fee).')'
                        : 'Permanent add-on',
                ];
            } else {
                $guaranteeRow[] = ['label' => 'Link guarantee', 'value' => 'Not added'];
            }
        } elseif ($order->link_guarantee) {
            $guaranteeRow[] = [
                'label' => 'Link guarantee',
                'value' => '12-month add-on',
            ];
        }

        return static::filledMailRows(array_merge(
            [['label' => 'Order', 'value' => $order->order_number ? '#'.$order->order_number : '']],
            $dateRows,
            $productRow,
            [
                ['label' => 'Payment method', 'value' => $method],
                ['label' => 'Total', 'value' => static::euro($total)],
                ['label' => 'Reference code', 'value' => trim((string) ($order->reference_code ?? ''))],
            ],
            $guaranteeRow
        ));
    }

    /**
     * @param  iterable<mixed>  $items
     * @return list<array{site_name: string, site_url: ?string, price: string, content_link: ?string}>
     */
    public static function orderPlacedItemCards(iterable $items): array
    {
        $list = [];
        foreach ($items as $item) {
            if ($item instanceof OrderItem) {
                $list[] = $item;
            }
        }
        $showPrice = count($list) > 1;
        $cards = [];
        foreach ($list as $item) {
            $url = static::safeLiveUrl($item->site_url ?? $item->site?->site_url ?? null);
            $domain = static::siteDomain($item) ?: static::siteName($item);
            $guaranteeFee = round((float) ($item->link_guarantee_fee ?? 0), 2);
            $cards[] = [
                'site_name' => $domain,
                'domain' => $domain,
                'site_url' => $url,
                'site_url_display' => $url ? static::displayUrl($url) : null,
                'price' => static::euro(static::advertiserFacingItemPrice($item)),
                'show_price' => $showPrice,
                'content_link' => static::safeLiveUrl($item->content_link ?? null),
                'product_type_label' => $item->productTypeLabel(),
                'link_guarantee' => $item->hasPaidLinkGuarantee(),
                'link_guarantee_fee_label' => ($item->hasPaidLinkGuarantee() && $guaranteeFee > 0)
                    ? static::euro($guaranteeFee)
                    : null,
            ];
        }

        return $cards;
    }

    /**
     * Homepage / social extras for article-live details (never includes amounts).
     *
     * @return array{homepage_placement: ?string, social_promotion: ?string, social_urls: list<array{label: string, url: string}>}
     */
    public static function articleLiveExtras(OrderItem $item): array
    {
        $homepage = null;
        if ($item->hasHomepagePlacement()) {
            $days = (int) $item->homepage_days;
            $homepage = $days.' day'.($days === 1 ? '' : 's');
        }

        $social = null;
        if ($item->offersSocialPromotion()) {
            $labels = collect($item->enabledSocialChannels())
                ->map(fn (string $channel) => $item->socialChannelLabel($channel))
                ->filter()
                ->implode(', ');
            if ($labels !== '') {
                $social = $labels.' (included)';
            }
        }

        $socialUrls = [];
        foreach ($item->socialPostUrls() as $channel => $url) {
            $safe = static::safeLiveUrl($url);
            if ($safe) {
                $socialUrls[] = [
                    'label' => $item->socialChannelLabel((string) $channel),
                    'url' => $safe,
                ];
            }
        }

        return [
            'homepage_placement' => $homepage,
            'social_promotion' => $social,
            'social_urls' => $socialUrls,
        ];
    }

    public static function safeLiveUrl(mixed $url): ?string
    {
        $safe = function_exists('safe_href_url') ? safe_href_url($url) : null;
        if (! is_string($safe) || $safe === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $safe) !== 1) {
            return null;
        }

        return $safe;
    }

    public static function siteName(?OrderItem $item): string
    {
        $name = trim((string) ($item?->site?->site_name ?? $item?->site_name ?? ''));

        return $name !== '' ? $name : 'Website';
    }

    public static function siteDomain(?OrderItem $item): ?string
    {
        $candidates = [
            $item?->site?->domain ?? null,
            $item?->site?->site_url ?? null,
            $item?->site_url ?? null,
        ];

        foreach ($candidates as $candidate) {
            $host = static::bareHost($candidate);
            if ($host) {
                return $host;
            }
        }

        return null;
    }

    public static function siteLabel(?OrderItem $item): string
    {
        $domain = static::siteDomain($item);
        if ($domain) {
            return $domain;
        }

        return static::siteName($item);
    }

    /**
     * Domain for titles/subjects, or "{n} sites" when an order has several placements.
     */
    public static function orderSiteHeadline(?Order $order = null, ?OrderItem $item = null, mixed $site = null): string
    {
        if ($item instanceof OrderItem) {
            $domain = static::siteDomain($item);
            if ($domain) {
                return $domain;
            }
        }

        if (is_object($site)) {
            $host = static::bareHost($site->domain ?? $site->site_url ?? null);
            if ($host) {
                return $host;
            }
        }

        if ($order instanceof Order) {
            $order->loadMissing('items.site');
            $count = $order->items->count();
            if ($count > 1) {
                return $count.' sites';
            }
            $first = $order->items->first();
            if ($first instanceof OrderItem) {
                $domain = static::siteDomain($first);
                if ($domain) {
                    return $domain;
                }
            }
        }

        return 'your site';
    }

    public static function liveUrlRelevant(?string $orderStatus, string $context): bool
    {
        if (in_array($context, [self::CONTEXT_REVIEW_READY, self::CONTEXT_COMPLETED], true)) {
            return true;
        }

        return in_array(strtolower((string) $orderStatus), ['review', 'completed'], true);
    }

    public static function liveUrlForItem(?OrderItem $item, bool $relevant): ?string
    {
        if (! $relevant || ! $item) {
            return null;
        }

        return static::safeLiveUrl($item->live_url ?? null);
    }

    /**
     * @return list<array{site_label: string, site_name: string, domain: ?string, live_url: ?string, pending: bool}>
     */
    public static function placements(Order $order, string $context = self::CONTEXT_STATUS, ?string $statusOverride = null): array
    {
        $order->loadMissing(['items.site']);
        $relevant = static::liveUrlRelevant($statusOverride ?? (string) $order->status, $context);
        $rows = [];

        foreach ($order->items as $item) {
            if (! $item instanceof OrderItem) {
                continue;
            }

            $url = static::liveUrlForItem($item, $relevant);
            $rows[] = [
                'site_label' => static::siteLabel($item),
                'site_name' => static::siteName($item),
                'domain' => static::siteDomain($item),
                'live_url' => $url,
                'pending' => $relevant && $url === null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{live_url?: ?string}>  $placements
     */
    public static function hasAnyLiveUrl(array $placements): bool
    {
        foreach ($placements as $row) {
            if (! empty($row['live_url'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function summaryRows(
        Order $order,
        string $audience,
        string $context,
        ?string $statusLabel = null,
        ?string $paymentLabel = null,
        ?string $statusOverride = null,
    ): array {
        $placements = static::placements($order, $context, $statusOverride);
        $websiteValue = '—';
        $domains = collect($placements)->pluck('domain')->filter()->unique()->values();
        if ($domains->count() === 1) {
            $websiteValue = (string) $domains->first();
        } elseif ($domains->count() > 1) {
            $websiteValue = $domains->implode(', ');
        } elseif (count($placements) === 1) {
            $websiteValue = $placements[0]['site_label'];
        }

        $rows = static::filledMailRows([
            ['label' => 'Order', 'value' => $order->order_number ? '#'.$order->order_number : ''],
            ['label' => 'Website', 'value' => $websiteValue],
        ]);

        if ($context !== self::CONTEXT_REVIEW_READY && $context !== self::CONTEXT_PAYMENT) {
            $rows[] = [
                'label' => 'Order status',
                'value' => $statusLabel ?: static::orderStatusLabel((string) $order->status),
            ];
        }

        $showMoney = in_array($context, [self::CONTEXT_PAYMENT, self::CONTEXT_RECEIPT], true)
            || ($context === self::CONTEXT_CREATED && in_array($audience, ['advertiser', 'admin'], true));

        // Publishers must never see advertiser order totals / markup.
        if ($showMoney && $audience !== 'publisher') {
            if ($context !== self::CONTEXT_CREATED) {
                $rows[] = [
                    'label' => 'Payment status',
                    'value' => $paymentLabel ?: static::paymentStatusLabel((string) $order->payment_status),
                ];
            }
            $rows[] = [
                'label' => 'Amount',
                'value' => static::euro((float) $order->total_amount),
            ];
        }

        return $rows;
    }

    public static function euro(float $amount, string $symbol = '€'): string
    {
        $cents = (int) round($amount * 100);
        if ($cents % 100 === 0) {
            return $symbol.number_format($cents / 100, 0, '.', '');
        }

        return $symbol.number_format($cents / 100, 2, '.', '');
    }

    public static function repliesAreMonitored(): bool
    {
        $brand = config('email_notifications.brand', []);
        $replyTo = strtolower(trim((string) ($brand['reply_to'] ?? '')));
        $from = strtolower(trim((string) ($brand['sender_email'] ?? config('mail.from.address', ''))));
        $support = strtolower(trim((string) ($brand['support_email'] ?? '')));

        if ($replyTo === '' || $from === '') {
            return false;
        }

        if ($replyTo === $from) {
            return false;
        }

        return $support !== '' && $replyTo === $support;
    }

    public static function supportEmail(): ?string
    {
        $email = trim((string) config('email_notifications.brand.support_email', ''));

        return $email !== '' ? $email : null;
    }

    public static function supportLine(string $intent = 'order'): string
    {
        if ($intent === 'order') {
            return '';
        }

        $support = static::supportEmail();
        if ($intent === 'bulk') {
            return $support
                ? 'If this looks like a mistake, contact support at '.$support.'.'
                : 'If this looks like a mistake, open your sites page or contact support.';
        }

        return $support
            ? 'If you have questions, contact support at '.$support.'.'
            : 'If you have questions, contact support from your account.';
    }

    public static function isReviewReady(string $changeKind, string $newValue, Order $order): bool
    {
        if ($changeKind !== 'status' || strtolower($newValue) !== 'review') {
            return false;
        }

        return static::hasAnyLiveUrl(static::placements($order, self::CONTEXT_REVIEW_READY));
    }

    /**
     * Exact auto-approve instant: live_url_submitted_at + configured window (default 72h).
     */
    public static function autoApproveDeadlineAt(?OrderItem $item): ?Carbon
    {
        if (! $item) {
            return null;
        }

        $start = $item->live_url_submitted_at;
        if (! $start instanceof CarbonInterface) {
            if (blank($item->live_url_submitted_at)) {
                return null;
            }
            try {
                $start = Carbon::parse((string) $item->live_url_submitted_at);
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return $start->copy()->addHours(OrderItem::autoApproveHours());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True only when a stored check actually verified a dofollow live link.
     * Listing `link_type` and HTTP reachability are not enough — never invent this.
     */
    public static function dofollowVerified(?OrderItem $item): bool
    {
        if (! $item) {
            return false;
        }

        foreach (['dofollow_verified', 'live_link_dofollow_verified', 'rel_dofollow_verified'] as $attribute) {
            if (array_key_exists($attribute, $item->getAttributes()) && $item->getAttribute($attribute) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Show the green pill when a stored check verified dofollow, or the listing
     * is sold as dofollow. Never invent the pill for nofollow / sponsored.
     */
    public static function listingIsDofollow(?OrderItem $item): bool
    {
        if (! $item) {
            return false;
        }

        foreach ([$item->getAttribute('link_type'), $item->site?->link_type ?? null] as $candidate) {
            $type = strtolower(trim((string) $candidate));
            if ($type === 'dofollow' || str_starts_with($type, 'dofollow')) {
                return true;
            }
        }

        return false;
    }

    public static function showDofollowPill(?OrderItem $item): bool
    {
        return static::dofollowVerified($item) || static::listingIsDofollow($item);
    }

    /**
     * Homepage screenshot/thumb for a placement, if the app already stored one.
     */
    public static function articlePreviewUrl(?OrderItem $item): ?string
    {
        $site = $item?->site;
        if (! $site) {
            return null;
        }

        $origin = rtrim(function_exists('brand_public_origin') ? brand_public_origin() : '', '/');
        if ($origin === '' && function_exists('app_public_url')) {
            $origin = rtrim((string) app_public_url(), '/');
        }

        if (! method_exists($site, 'homepagePreviewUrlChain')) {
            return null;
        }

        foreach ($site->homepagePreviewUrlChain() as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);
            if (method_exists($site, 'isPlaceholderPreviewPath') && Site::isPlaceholderPreviewPath($url)) {
                continue;
            }
            if (preg_match('#^https?://#i', $url) === 1) {
                return static::safeLiveUrl($url);
            }
            if (str_starts_with($url, '/media/') || str_starts_with($url, '/storage/')) {
                return $origin !== '' ? $origin.$url : null;
            }
        }

        return null;
    }
}
