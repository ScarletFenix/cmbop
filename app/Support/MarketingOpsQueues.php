<?php

namespace App\Support;

use App\Models\BulkSiteRequest;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared marketing / staff ops queue queries.
 *
 * Dashboard cards and the bulk index "open" count must use the same predicates
 * so leftover Done rows (completed + pending items) are not invisible.
 */
class MarketingOpsQueues
{
    /**
     * Sites ready for staff activate / review (not publisher drafts or invites).
     *
     * @return Builder<Site>
     */
    public static function sitesReadyForStaff(): Builder
    {
        return Site::query()->needsAdminReview();
    }

    /**
     * Unpublished listings still with the publisher (details or accept).
     *
     * @return Builder<Site>
     */
    public static function sitesWaitingOnPublisher(): Builder
    {
        return Site::query()
            ->where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })
            ->where(function ($q) {
                $q->where('active', 0)->orWhereNull('active');
            })
            ->where(function ($q) {
                $q->whereIn('onboarding_status', [
                    Site::ONBOARDING_AWAITING_DETAILS,
                    Site::ONBOARDING_DETAILS_COMPLETE,
                ]);

                if (Site::hasSitesColumn('publisher_accepted_at')
                    && Site::hasSitesColumn('assigned_by_user_id')) {
                    $q->orWhere(function ($invite) {
                        $invite->whereNull('publisher_accepted_at')
                            ->whereNotNull('assigned_by_user_id');
                    });
                }
            });
    }

    /**
     * Every bulk request that still needs someone — including completed
     * batches that still have URL+price rows for Done.
     *
     * @return Builder<BulkSiteRequest>
     */
    public static function openBulkForMarketer(): Builder
    {
        return BulkSiteRequest::query()->where(function ($q) {
            $q->whereNotIn('status', [
                BulkSiteRequest::STATUS_COMPLETED,
                BulkSiteRequest::STATUS_CANCELLED,
            ])->orWhere(function ($inner) {
                $inner->where('status', BulkSiteRequest::STATUS_COMPLETED)
                    ->whereHas('items', fn ($items) => $items->whereNull('site_id'));
            });
        });
    }

    /**
     * Bulk work the marketer can do now (not waiting on the publisher).
     *
     * @return Builder<BulkSiteRequest>
     */
    public static function bulkWaitingOnMarketer(): Builder
    {
        return BulkSiteRequest::query()->where(function ($q) {
            $q->whereIn('status', [
                BulkSiteRequest::STATUS_REQUESTED,
                BulkSiteRequest::STATUS_SHEET_SENT,
                BulkSiteRequest::STATUS_SEEDED,
            ])->orWhere(function ($inner) {
                $inner->where('status', BulkSiteRequest::STATUS_COMPLETED)
                    ->whereHas('items', fn ($items) => $items->whereNull('site_id'));
            });
        });
    }

    /**
     * Seeded drafts still with the publisher.
     *
     * @return Builder<BulkSiteRequest>
     */
    public static function bulkWaitingOnPublisher(): Builder
    {
        return BulkSiteRequest::query()
            ->where('status', BulkSiteRequest::STATUS_AWAITING_PUBLISHER);
    }

    public static function siteQueueLabel(Site $site): string
    {
        if ($site->isPendingPublisherAcceptance()) {
            return 'Waiting on accept';
        }

        return match ($site->onboarding_status) {
            Site::ONBOARDING_AWAITING_DETAILS => 'Filling details',
            Site::ONBOARDING_DETAILS_COMPLETE => 'Publisher reviewing',
            Site::ONBOARDING_READY_FOR_REVIEW => 'Ready for review',
            default => 'Needs review',
        };
    }
}
