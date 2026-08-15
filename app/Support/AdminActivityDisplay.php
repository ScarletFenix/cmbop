<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\AdBanner;
use App\Models\Blog;
use App\Models\BulkSiteRequest;
use App\Models\ContentSubmission;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Site;
use App\Models\SiteAnnouncement;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;

/**
 * Batch lookups and row fields for admin activity history (no per-row exists()).
 */
class AdminActivityDisplay
{
    /** @var array<string, string> */
    private const CHANGE_KEY_LABELS = [
        'site_name' => 'Name',
        'site_url' => 'URL',
        'domain' => 'Domain',
        'da' => 'DA',
        'dr' => 'DR',
        'traffic' => 'Traffic',
        'price' => 'Price',
        'language' => 'Language',
        'country' => 'Country',
        'category' => 'Niches',
        'categories' => 'Niches',
        'active' => 'Active',
        'verified' => 'Verified',
        'description' => 'Description',
        'site_image' => 'Image',
        'link_type' => 'Link type',
        'publication_time' => 'Publication',
        'company_name' => 'Company',
        'status' => 'Status',
    ];

    /** @var list<string> */
    private const DELETED_ACTIONS = [
        'site.deleted',
        'site.deleted_by_marketing',
        'blog.deleted',
        'announcement.deleted',
        'banner.deleted',
        'site.rating_deleted',
    ];

    /**
     * @param  iterable<int, ActivityLog>  $logs
     * @return array{
     *     existingSiteIds: array<int, true>,
     *     existingBulkIds: array<int, true>,
     *     existingUserIds: array<int, true>,
     *     existingOrderIds: array<int, true>,
     *     existingDepositIds: array<int, true>,
     *     existingWithdrawalIds: array<int, true>,
     *     existingInvoiceIds: array<int, true>,
     *     existingBlogIds: array<int, true>,
     *     existingWalletIds: array<int, int>,
     *     existingAnnouncementIds: array<int, true>,
     *     existingBannerIds: array<int, true>,
     *     existingSubmissionIds: array<int, true>
     * }
     */
    public static function preload(iterable $logs): array
    {
        $buckets = [
            'site' => [],
            'bulk' => [],
            'user' => [],
            'order' => [],
            'deposit' => [],
            'withdrawal' => [],
            'invoice' => [],
            'blog' => [],
            'wallet' => [],
            'announcement' => [],
            'banner' => [],
            'submission' => [],
        ];

        foreach ($logs as $log) {
            self::collectId($buckets, (string) $log->subject_type, (int) $log->subject_id);
            $props = is_array($log->properties) ? $log->properties : [];
            foreach ([
                'site_id' => 'site',
                'bulk_site_request_id' => 'bulk',
                'user_id' => 'user',
                'order_id' => 'order',
                'deposit_id' => 'deposit',
                'withdrawal_id' => 'withdrawal',
                'invoice_id' => 'invoice',
                'blog_id' => 'blog',
                'wallet_id' => 'wallet',
                'submission_id' => 'submission',
            ] as $key => $bucket) {
                $id = (int) data_get($props, $key);
                if ($id > 0) {
                    $buckets[$bucket][$id] = $id;
                }
            }
        }

        return [
            'existingSiteIds' => self::existingKeys(Site::class, $buckets['site']),
            'existingBulkIds' => self::existingKeys(BulkSiteRequest::class, $buckets['bulk']),
            'existingUserIds' => self::existingKeys(User::class, $buckets['user']),
            'existingOrderIds' => self::existingKeys(Order::class, $buckets['order']),
            'existingDepositIds' => self::existingKeys(DepositRequest::class, $buckets['deposit']),
            'existingWithdrawalIds' => self::existingKeys(Withdrawal::class, $buckets['withdrawal']),
            'existingInvoiceIds' => self::existingKeys(Invoice::class, $buckets['invoice']),
            'existingBlogIds' => self::existingKeys(Blog::class, $buckets['blog']),
            'existingWalletIds' => self::walletUserIds($buckets['wallet']),
            'existingAnnouncementIds' => self::existingKeys(SiteAnnouncement::class, $buckets['announcement']),
            'existingBannerIds' => self::existingKeys(AdBanner::class, $buckets['banner']),
            'existingSubmissionIds' => self::existingKeys(ContentSubmission::class, $buckets['submission']),
        ];
    }

    /**
     * @param  array<string, mixed>  $lookup
     */
    public static function subjectUrl(?ActivityLog $log, array $lookup = []): ?string
    {
        if (! $log || in_array($log->action, self::DELETED_ACTIONS, true)) {
            return null;
        }

        $type = (string) $log->subject_type;
        $id = (int) $log->subject_id;

        if ($id > 0) {
            $url = self::urlForType($type, $id, $lookup);
            if ($url) {
                return $url;
            }
        }

        // Only fall back to the same family of subject (site / bulk). Guessing
        // user_id/order_id from properties can send an admin to the wrong record.
        $props = is_array($log->properties) ? $log->properties : [];
        $siteId = (int) data_get($props, 'site_id');
        if ($siteId > 0) {
            $url = self::urlForType(Site::class, $siteId, $lookup);
            if ($url) {
                return $url;
            }
        }

        $bulkId = (int) data_get($props, 'bulk_site_request_id');

        return $bulkId > 0
            ? self::urlForType(BulkSiteRequest::class, $bulkId, $lookup)
            : null;
    }

    public static function reason(?ActivityLog $log): ?string
    {
        foreach (['reason', 'note'] as $key) {
            $value = trim((string) data_get($log?->properties, $key));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function reasonLabel(?ActivityLog $log): string
    {
        return $log?->action === 'bulk_request.items_rejected' ? 'Note' : 'Reason';
    }

    /**
     * @return list<string>
     */
    public static function changeKeys(?ActivityLog $log): array
    {
        $changes = data_get($log?->properties, 'changes');
        if (! is_array($changes) || $changes === []) {
            return [];
        }

        $labels = [];
        foreach (array_keys($changes) as $key) {
            $key = (string) $key;
            $labels[] = self::CHANGE_KEY_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
        }

        return array_values(array_unique($labels));
    }

    public static function statusChange(?ActivityLog $log): ?string
    {
        $from = data_get($log?->properties, 'from');
        $to = data_get($log?->properties, 'to');
        if (! is_scalar($from) || ! is_scalar($to)) {
            return null;
        }

        $fromText = trim((string) $from);
        $toText = trim((string) $to);
        if ($fromText === '' || $toText === '') {
            return null;
        }

        // site.approved / site.activated store verified/active as 0/1 — the
        // action label already says what happened; "0 → 1" is not an audit status.
        if (self::isFlagPair($from, $to)) {
            return null;
        }

        return $fromText.' → '.$toText;
    }

    /**
     * @param  array<string, mixed>  $lookup
     */
    public static function isRemoved(?ActivityLog $log, array $lookup): bool
    {
        if (! $log) {
            return false;
        }

        if (in_array($log->action, self::DELETED_ACTIONS, true)) {
            return true;
        }

        $type = (string) $log->subject_type;
        $id = (int) $log->subject_id;
        if ($id <= 0) {
            return false;
        }

        $map = [
            Site::class => 'existingSiteIds',
            BulkSiteRequest::class => 'existingBulkIds',
            User::class => 'existingUserIds',
            Order::class => 'existingOrderIds',
            DepositRequest::class => 'existingDepositIds',
            Withdrawal::class => 'existingWithdrawalIds',
            Invoice::class => 'existingInvoiceIds',
            Blog::class => 'existingBlogIds',
            SiteAnnouncement::class => 'existingAnnouncementIds',
            AdBanner::class => 'existingBannerIds',
            ContentSubmission::class => 'existingSubmissionIds',
            Wallet::class => 'existingWalletIds',
        ];

        $key = $map[$type] ?? null;
        if ($key === null) {
            return false;
        }

        return ! isset($lookup[$key][$id]);
    }

    /**
     * @param  array<string, array<int, int>>  $buckets
     */
    private static function collectId(array &$buckets, string $type, int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $map = [
            Site::class => 'site',
            BulkSiteRequest::class => 'bulk',
            User::class => 'user',
            Order::class => 'order',
            DepositRequest::class => 'deposit',
            Withdrawal::class => 'withdrawal',
            Invoice::class => 'invoice',
            Blog::class => 'blog',
            Wallet::class => 'wallet',
            SiteAnnouncement::class => 'announcement',
            AdBanner::class => 'banner',
            ContentSubmission::class => 'submission',
        ];

        $bucket = $map[$type] ?? null;
        if ($bucket) {
            $buckets[$bucket][$id] = $id;
        }
    }

    /**
     * @param  class-string  $model
     * @param  array<int, int>  $ids
     * @return array<int, true>
     */
    private static function existingKeys(string $model, array $ids): array
    {
        if ($ids === [] || ! class_exists($model)) {
            return [];
        }

        try {
            $existing = [];
            foreach ($model::query()->whereIn('id', array_values($ids))->pluck('id') as $id) {
                $existing[(int) $id] = true;
            }

            return $existing;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private static function walletUserIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            $map = [];
            foreach (Wallet::query()->whereIn('id', array_values($ids))->get(['id', 'user_id']) as $wallet) {
                $map[(int) $wallet->id] = (int) $wallet->user_id;
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $lookup
     */
    private static function urlForType(string $type, int $id, array $lookup): ?string
    {
        return match ($type) {
            Site::class => isset($lookup['existingSiteIds'][$id])
                ? route('admin.sites.edit', $id)
                : null,
            BulkSiteRequest::class => isset($lookup['existingBulkIds'][$id])
                ? route('admin.bulk-site-requests.show', $id)
                : null,
            User::class => isset($lookup['existingUserIds'][$id])
                ? route('admin.users.index', ['user' => $id])
                : null,
            Order::class => isset($lookup['existingOrderIds'][$id])
                ? route('admin.orders.show', $id)
                : null,
            DepositRequest::class => isset($lookup['existingDepositIds'][$id])
                ? route('admin.deposits.show', $id)
                : null,
            Withdrawal::class => isset($lookup['existingWithdrawalIds'][$id])
                ? route('admin.withdrawals.show', $id)
                : null,
            Invoice::class => isset($lookup['existingInvoiceIds'][$id])
                ? route('admin.invoices.show', $id)
                : null,
            Blog::class => isset($lookup['existingBlogIds'][$id])
                ? route('admin.blogs.edit', $id)
                : null,
            Wallet::class => ($lookup['existingWalletIds'][$id] ?? 0) > 0
                ? route('admin.finance.user', $lookup['existingWalletIds'][$id])
                : null,
            SiteAnnouncement::class => isset($lookup['existingAnnouncementIds'][$id])
                ? route('admin.promotions.announcements.edit', $id)
                : null,
            AdBanner::class => isset($lookup['existingBannerIds'][$id])
                ? route('admin.promotions.banners.edit', $id)
                : null,
            ContentSubmission::class => isset($lookup['existingSubmissionIds'][$id])
                ? route('admin.content-library.show', $id)
                : null,
            default => null,
        };
    }

    private static function isFlagPair(mixed $from, mixed $to): bool
    {
        $flags = [0, 1, '0', '1', true, false];

        return in_array($from, $flags, true) && in_array($to, $flags, true);
    }
}
