<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;

/**
 * Presentation map for the shared transactional mail chrome.
 *
 * Does not change when mail is sent — it only labels status, progress,
 * preheaders, and footer links from data the mailable already has.
 */
class TransactionalMailTheme
{
    public const LOGO_URL = 'https://seolinkbuildings.com/assets/img/logo1.png';

    public const SUCCESS_ICON_PATH = 'assets/img/email/success-check.gif';

    public const COLOR_PRIMARY = '#1a585e';

    public const COLOR_DARK = '#123f42';

    public const COLOR_THEME = '#0b6266';

    public const COLOR_ACCENT = '#3faeb2';

    public const COLOR_LIGHT = '#e6f5f5';

    public const COLOR_LIGHT_ALT = '#d4f1f0';

    public const COLOR_SURFACE = '#f7fafb';

    public const COLOR_FOOTER = '#f4fbfb';

    public const COLOR_BORDER = '#e2e8f0';

    public const STATUS_PLACED = 'placed';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_ARTICLE_LIVE = 'article_live';

    public const STATUS_ACTION_NEEDED = 'action_needed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAYMENT_RELEASED = 'payment_released';

    public const STATUS_WALLET = 'wallet';

    public const STATUS_NEW_SITES = 'new_sites';

    /**
     * @var array<string, array{label: string, bg: string, fg: string}>
     */
    public const PILLS = [
        self::STATUS_PLACED => ['label' => 'Order placed', 'bg' => '#e6f5f5', 'fg' => '#1a585e'],
        self::STATUS_ACCEPTED => ['label' => 'Accepted', 'bg' => '#e0f2fe', 'fg' => '#0369a1'],
        self::STATUS_ARTICLE_LIVE => ['label' => 'Article live', 'bg' => '#d4f1f0', 'fg' => '#0b6266'],
        self::STATUS_ACTION_NEEDED => ['label' => 'Action needed', 'bg' => '#fef3c7', 'fg' => '#b45309'],
        self::STATUS_REJECTED => ['label' => 'Rejected', 'bg' => '#fee2e2', 'fg' => '#b91c1c'],
        self::STATUS_PAYMENT_RELEASED => ['label' => 'Payment released', 'bg' => '#dcfce7', 'fg' => '#15803d'],
        self::STATUS_WALLET => ['label' => 'Wallet top-up', 'bg' => '#ede9fe', 'fg' => '#6d28d9'],
        self::STATUS_NEW_SITES => ['label' => 'New sites', 'bg' => '#e6f5f5', 'fg' => '#123f42'],
    ];

    public const PROGRESS_STEPS = ['Placed', 'Accepted', 'Published', 'Approved'];

    /**
     * @var list<string>
     */
    public const MARKETING_TYPES = [
        'audience_campaign',
        'weekly_new_sites',
        'new_sites_digest',
        'saved_search_alert',
        'abandoned_cart_reminder',
        'trustpilot_review',
        'deposit_reminder',
        'publisher_add_site_reminder',
    ];

    /**
     * @var list<string>
     */
    public const SUCCESS_STATUSES = [
        self::STATUS_PLACED,
        self::STATUS_ACCEPTED,
        self::STATUS_ARTICLE_LIVE,
        self::STATUS_PAYMENT_RELEASED,
        self::STATUS_WALLET,
    ];

    public string $status = self::STATUS_PLACED;

    public string $preheader = '';

    public string $heading = '';

    public string $subline = '';

    public string $pillLabel = 'Order placed';

    public string $pillBg = '#e6f5f5';

    public string $pillFg = '#1a585e';

    public bool $showSuccessIcon = false;

    public string $successIconUrl = '';

    public bool $showProgress = false;

    /** 1-based index into PROGRESS_STEPS, or 0 when none. */
    public int $currentStep = 0;

    public bool $isMarketing = false;

    public ?string $unsubscribeUrl = null;

    public string $helpUrl = '';

    public string $notificationSettingsUrl = '';

    public string $supportEmail = 'support@seolinkbuildings.com';

    public string $logoUrl = self::LOGO_URL;

    public string $siteUrl = 'https://seolinkbuildings.com';

    public string $brandName = 'SEOLinkBuildings';

    public bool $hideFirstBodyHeading = true;

    public bool $showPill = true;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromViewData(array $data): self
    {
        $theme = new self;
        $theme->hydrateBrand();
        $theme->status = self::resolveStatus($data);
        $mapped = self::isMapped($data, $theme->status);
        $pill = self::PILLS[$theme->status] ?? self::PILLS[self::STATUS_PLACED];
        $theme->showPill = $mapped;
        $theme->pillLabel = $mapped ? $pill['label'] : '';
        $theme->pillBg = $pill['bg'];
        $theme->pillFg = $pill['fg'];
        $theme->showSuccessIcon = $mapped && in_array($theme->status, self::SUCCESS_STATUSES, true);
        $theme->successIconUrl = self::successIconUrl();
        $theme->heading = $mapped ? self::resolveHeading($data, $theme->status) : trim((string) ($data['heading'] ?? ''));
        $theme->subline = $mapped ? self::resolveSubline($data, $theme->status) : '';
        $theme->preheader = self::resolvePreheader($data, $mapped ? $theme->status : '');
        $theme->hideFirstBodyHeading = $theme->heading !== '';
        $theme->currentStep = $mapped ? self::resolveProgressStep($data) : 0;
        $theme->showProgress = $theme->currentStep > 0 && self::hasOrderContext($data);
        $type = self::notificationType($data);
        $theme->isMarketing = in_array($type, self::MARKETING_TYPES, true)
            || filled($data['unsubscribeUrl'] ?? null);
        $theme->unsubscribeUrl = filled($data['unsubscribeUrl'] ?? null)
            ? (string) $data['unsubscribeUrl']
            : null;
        $theme->helpUrl = self::publicPath('/faq');
        $theme->notificationSettingsUrl = filled($data['preferencesUrl'] ?? null)
            ? (string) $data['preferencesUrl']
            : self::publicPath('/profile/notifications');
        $support = MailNotificationPresenter::supportEmail();
        if (is_string($support) && $support !== '') {
            $theme->supportEmail = $support;
        }

        return $theme;
    }

    public static function successIconUrl(): string
    {
        $path = ltrim(self::SUCCESS_ICON_PATH, '/');
        $absolute = public_path($path);
        $version = is_file($absolute) ? (string) filemtime($absolute) : (string) time();
        $origin = rtrim(self::publicOrigin(), '/');

        return $origin.'/'.$path.'?v='.$version;
    }

    public static function publicOrigin(): string
    {
        if (function_exists('brand_public_origin')) {
            $origin = rtrim((string) brand_public_origin(), '/');
            if ($origin !== '' && (! function_exists('url_is_loopback') || ! url_is_loopback($origin))) {
                return $origin;
            }
        }

        return 'https://seolinkbuildings.com';
    }

    public static function publicPath(string $path): string
    {
        return rtrim(self::publicOrigin(), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function notificationType(array $data): string
    {
        $type = strtolower(trim((string) ($data['notificationType'] ?? '')));
        if ($type !== '') {
            return $type;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function isMapped(array $data, string $status): bool
    {
        $type = self::notificationType($data);
        $known = [
            'order_status_changed', 'order_payment_confirmed', 'order_accepted', 'order_rejected',
            'order_completed', 'live_url_submitted', 'publisher_new_order', 'partner_new_order',
            'deposit_approved', 'weekly_new_sites', 'new_sites_digest', 'modification_requested',
            'article_revision_requested', 'content_revision_requested', 'advertiser_review_nudge',
            'auto_approve_reminder', 'partner_payout_released', 'withdrawal_status',
            'managed_order_completed', 'managed_order_confirmed', 'payment_successful_invoice',
            'saved_search_alert', 'publisher_accept_nudge', 'publisher_publish_nudge',
            'advertiser_order_stalled', 'payment_failed', 'deposit_rejected',
            'partner_correction', 'link_guarantee_restore',             'article_ready_for_approval',
            'article_approved_for_publish',
            'pack_progress',
        ];
        if (in_array($type, $known, true)) {
            return true;
        }

        return self::hasOrderContext($data)
            || isset($data['cards'])
            || isset($data['deposit'])
            || ! empty($data['reviewReady']);
    }

    public static function resolveStatus(array $data): string
    {
        $type = self::notificationType($data);
        $changeKind = strtolower((string) ($data['changeKind'] ?? ''));
        $newValue = strtolower((string) ($data['newValue'] ?? ''));
        $order = $data['order'] ?? null;
        $orderStatus = strtolower((string) (is_object($order) ? ($order->status ?? '') : ''));

        if ($type === 'pack_progress') {
            return ! empty($data['complete']) ? self::STATUS_PAYMENT_RELEASED : self::STATUS_ARTICLE_LIVE;
        }

        if (! empty($data['reviewReady'])
            || in_array($type, ['live_url_submitted', 'advertiser_review_nudge', 'auto_approve_reminder'], true)
            || (isset($data['bodyCopy']) && isset($data['orderItem']) && str_contains(strtolower((string) ($data['bodyCopy'] ?? '')), 'article is live'))
            || ($changeKind === 'status' && in_array($newValue, ['review', 'in_review'], true) && ! empty($data['reviewReady']))) {
            return self::STATUS_ARTICLE_LIVE;
        }

        if (in_array($type, ['weekly_new_sites', 'new_sites_digest', 'saved_search_alert'], true)
            || isset($data['cards'])
            || isset($data['weekKey'])) {
            return self::STATUS_NEW_SITES;
        }

        if (in_array($type, ['deposit_approved'], true)
            || ! empty($data['isInstant'])
            || (isset($data['deposit']) && empty($data['withdrawal']))) {
            if (in_array($type, ['deposit_rejected', 'deposit_refunded', 'paypal_payment_not_completed'], true)) {
                return self::STATUS_REJECTED;
            }
            if ($type === 'deposit_approved' || ! empty($data['isInstant'])) {
                return self::STATUS_WALLET;
            }
        }

        if (in_array($type, ['order_rejected', 'bulk_request_cancelled', 'payment_failed', 'deposit_rejected'], true)
            || in_array($newValue, ['cancelled', 'canceled', 'rejected'], true)
            || in_array($orderStatus, ['cancelled', 'canceled', 'rejected'], true)) {
            return self::STATUS_REJECTED;
        }

        if (in_array($type, [
            'order_completed',
            'partner_payout_released',
            'withdrawal_status',
            'managed_order_completed',
        ], true) || $newValue === 'completed' || $orderStatus === 'completed') {
            if (! empty($data['advertiserCompleted']) || $type === 'order_completed' || $newValue === 'completed') {
                return self::STATUS_PAYMENT_RELEASED;
            }
        }

        if (in_array($type, [
            'modification_requested',
            'article_revision_requested',
            'content_revision_requested',
            'content_revision_fulfilled',
            'publisher_accept_nudge',
            'publisher_publish_nudge',
            'advertiser_order_stalled',
            'admin_stalled_order',
            'payment_pending',
            'link_guarantee_restore',
            'partner_correction',
            'article_ready_for_approval',
            'writing_deadline_reminder',
            'content_revision_fulfilled',
        ], true)) {
            return self::STATUS_ACTION_NEEDED;
        }

        if (in_array($type, ['order_accepted'], true)
            || ($changeKind === 'status' && in_array($newValue, ['processing', 'in_progress'], true))
            || in_array($orderStatus, ['processing', 'in_progress'], true)) {
            return self::STATUS_ACCEPTED;
        }

        if (in_array($type, [
            'order_payment_confirmed',
            'publisher_new_order',
            'partner_new_order',
            'managed_order_confirmed',
            'payment_successful_invoice',
        ], true) || $changeKind === 'created') {
            return self::STATUS_PLACED;
        }

        if ($type === 'live_url_submitted') {
            return self::STATUS_ARTICLE_LIVE;
        }

        if (isset($data['deposit']) && is_object($data['deposit'])) {
            $depositStatus = strtolower((string) ($data['deposit']->status ?? ''));
            if (in_array($depositStatus, ['rejected', 'refunded', 'failed'], true)) {
                return self::STATUS_REJECTED;
            }

            return self::STATUS_WALLET;
        }

        return self::STATUS_PLACED;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveHeading(array $data, string $status): string
    {
        $heading = trim((string) ($data['heading'] ?? ''));
        if ($heading !== '') {
            return $heading;
        }

        $order = $data['order'] ?? null;
        $orderNumber = is_object($order) ? (string) ($order->order_number ?? '') : '';
        $suffix = $orderNumber !== '' ? ' — #'.$orderNumber : '';

        return match ($status) {
            self::STATUS_ARTICLE_LIVE => 'Your article is live'.$suffix,
            self::STATUS_ACCEPTED => 'Order accepted'.$suffix,
            self::STATUS_REJECTED => 'Order not accepted'.$suffix,
            self::STATUS_PAYMENT_RELEASED => 'Order approved'.$suffix,
            self::STATUS_WALLET => ! empty($data['isInstant']) ? 'Wallet topped up' : 'Deposit approved',
            self::STATUS_NEW_SITES => 'New sites in your niches',
            self::STATUS_ACTION_NEEDED => 'This order needs you'.$suffix,
            default => 'Order placed'.$suffix,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveSubline(array $data, string $status): string
    {
        $copy = trim((string) ($data['copy'] ?? $data['bodyCopy'] ?? ''));
        if ($copy !== '' && strlen($copy) <= 160 && ! str_contains($copy, "\n")) {
            return $copy;
        }

        return match ($status) {
            self::STATUS_ARTICLE_LIVE => 'Check the live post, then approve it or request corrections.',
            self::STATUS_ACCEPTED => 'The publisher accepted this order and work can begin.',
            self::STATUS_REJECTED => 'This placement was not accepted. Browse other sites to continue.',
            self::STATUS_PAYMENT_RELEASED => 'The live article was approved and payout can be released.',
            self::STATUS_WALLET => 'The funds are in your advertiser wallet and ready to spend.',
            self::STATUS_NEW_SITES => 'Newly listed sites that match placements you have paid for.',
            self::STATUS_ACTION_NEEDED => 'Open the order to finish the next step.',
            default => 'We will email you again when the publisher accepts.',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolvePreheader(array $data, string $status): string
    {
        if (filled($data['preheader'] ?? null)) {
            return trim((string) $data['preheader']);
        }

        if ($status === '') {
            return trim((string) ($data['heading'] ?? 'SEOLinkBuildings'));
        }

        $deadline = self::reviewDeadlineLabel($data);
        if ($status === self::STATUS_ARTICLE_LIVE && $deadline !== null) {
            return 'Review it by '.$deadline.'. Your payment stays protected until you approve.';
        }

        return match ($status) {
            self::STATUS_ARTICLE_LIVE => 'Review it by the deadline. Your payment stays protected until you approve.',
            self::STATUS_ACCEPTED => 'The publisher accepted your order. Writing can begin.',
            self::STATUS_REJECTED => 'This order was not accepted. Your funds stay in your wallet.',
            self::STATUS_PAYMENT_RELEASED => 'The article was approved and payment can be released.',
            self::STATUS_WALLET => 'Your wallet has been topped up and is ready to use.',
            self::STATUS_NEW_SITES => 'New catalog sites that match placements you have paid for.',
            self::STATUS_ACTION_NEEDED => 'This order needs your attention before it can continue.',
            default => 'Your order is in. We will email you when the publisher accepts.',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveProgressStep(array $data): int
    {
        if (! empty($data['reviewReady']) || self::notificationType($data) === 'live_url_submitted') {
            return 3;
        }

        $changeKind = strtolower((string) ($data['changeKind'] ?? ''));
        $newValue = strtolower((string) ($data['newValue'] ?? ''));
        $order = $data['order'] ?? null;
        $status = $newValue !== '' && $changeKind === 'status'
            ? $newValue
            : strtolower((string) (is_object($order) ? ($order->status ?? '') : ''));

        if ($changeKind === 'created' || self::notificationType($data) === 'order_payment_confirmed') {
            return 1;
        }

        return match ($status) {
            'pending', 'paid', 'scheduled' => 1,
            'processing', 'in_progress' => 2,
            'review', 'in_review' => 3,
            'completed' => 4,
            'cancelled', 'canceled', 'rejected' => 0,
            default => self::hasOrderContext($data) ? 1 : 0,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function hasOrderContext(array $data): bool
    {
        return isset($data['order']) && is_object($data['order']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function reviewDeadlineLabel(array $data): ?string
    {
        $item = $data['orderItem'] ?? $data['item'] ?? null;
        if (! $item instanceof OrderItem) {
            $order = $data['order'] ?? null;
            if ($order instanceof Order) {
                $order->loadMissing('items');
                $first = $order->items->first();
                $item = $first instanceof OrderItem ? $first : null;
            }
        }

        $at = MailNotificationPresenter::autoApproveDeadlineAt($item instanceof OrderItem ? $item : null);
        if (! $at) {
            return null;
        }

        return MailNotificationPresenter::formatDateTime($at);
    }

    /**
     * Payload for the article-live block. Amounts are never included.
     *
     * @return array{
     *     site_name: string,
     *     live_url: ?string,
     *     order_number: string,
     *     review_by: ?string,
     *     review_by_iso: ?string,
     *     anchor_text: ?string,
     *     target_url: ?string,
     *     dofollow_verified: bool,
     *     preview_url: ?string,
     *     approve_url: string,
     *     corrections_url: string,
     *     show_protection: bool,
     *     auto_approve_hours: int,
     *     homepage_placement: ?string,
     *     social_promotion: ?string,
     *     social_urls: list<array{label: string, url: string}>
     * }|null
     */
    public static function articleLivePayload(array $data, bool $advertiserAudience = true): ?array
    {
        $order = $data['order'] ?? null;
        if (! $order instanceof Order) {
            return null;
        }

        $item = $data['orderItem'] ?? $data['item'] ?? null;
        if (! $item instanceof OrderItem) {
            $order->loadMissing('items.site');
            $first = $order->items->first();
            $item = $first instanceof OrderItem ? $first : null;
        }
        if (! $item instanceof OrderItem) {
            return null;
        }

        if ($item->relationLoaded('site') === false && isset($data['site']) && $data['site'] instanceof Site) {
            $item->setRelation('site', $data['site']);
        }

        $liveUrl = MailNotificationPresenter::safeLiveUrl($data['liveUrl'] ?? $item->live_url ?? null);
        $deadline = MailNotificationPresenter::autoApproveDeadlineAt($item);
        $origin = rtrim(self::publicOrigin(), '/');
        $orderId = (int) $order->id;
        $approvePath = route('advertiser.orders', [
            'focus' => 'order',
            'order' => $orderId,
            'action' => 'approve',
        ], false);
        $correctionsPath = route('advertiser.orders', [
            'focus' => 'order',
            'order' => $orderId,
            'action' => 'modify',
        ], false);
        $approveUrl = $origin.$approvePath;
        $correctionsUrl = $origin.$correctionsPath;
        if (filled($data['ctaUrl'] ?? null) && is_string($data['ctaUrl'])) {
            $base = (string) $data['ctaUrl'];
            $approveUrl = $base.(str_contains($base, '?') ? '&' : '?').'action=approve';
            $correctionsUrl = $base.(str_contains($base, '?') ? '&' : '?').'action=modify';
        }

        MailNotificationPresenter::bindMailTimezone($order->user ?? null, $order);
        $target = MailNotificationPresenter::safeLiveUrl($item->briefTargetUrl());
        $anchor = $item->briefAnchorText();
        $extras = MailNotificationPresenter::articleLiveExtras($item);

        return [
            'site_name' => MailNotificationPresenter::siteLabel($item),
            'live_url' => $liveUrl,
            'order_number' => (string) $order->order_number,
            'review_by' => $deadline ? MailNotificationPresenter::formatDateTime($deadline) : null,
            'review_by_iso' => $deadline?->toIso8601String(),
            'anchor_text' => $anchor,
            'target_url' => $target,
            'dofollow_verified' => MailNotificationPresenter::dofollowVerified($item),
            'preview_url' => MailNotificationPresenter::articlePreviewUrl($item),
            'approve_url' => $approveUrl,
            'corrections_url' => $correctionsUrl,
            'show_protection' => $advertiserAudience,
            'auto_approve_hours' => OrderItem::autoApproveHours(),
            'homepage_placement' => $extras['homepage_placement'],
            'social_promotion' => $extras['social_promotion'],
            'social_urls' => $extras['social_urls'],
        ];
    }

    protected function hydrateBrand(): void
    {
        $this->logoUrl = self::LOGO_URL;
        $this->siteUrl = function_exists('mail_brand_website_url')
            ? mail_brand_website_url()
            : self::publicOrigin();
        $this->brandName = function_exists('mail_brand_name')
            ? mail_brand_name()
            : 'SEOLinkBuildings';
    }
}
