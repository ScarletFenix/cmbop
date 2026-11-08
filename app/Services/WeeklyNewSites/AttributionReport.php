<?php

namespace App\Services\WeeklyNewSites;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WeeklyNewSitesClick;
use App\Models\WeeklyNewSitesRecipient;
use App\Models\WeeklyNewSitesRun;
use App\Models\WeeklyNewSitesSend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Click and later-order counts. Labels are observational — a later paid
 * order for the same site is not claimed as caused by the email.
 */
class AttributionReport
{
    /**
     * @return array<string, mixed>
     */
    public function summary(?int $runId = null): array
    {
        $sends = 0;
        $clicks = 0;
        $siteClicks = 0;
        $ordersAfterClick = 0;
        $windowDays = max(1, (int) config('weekly_new_sites_digest.attribution_window_days', 14));

        if (WeeklyNewSitesSend::tableReady()) {
            $sendQuery = WeeklyNewSitesSend::query()->where('status', WeeklyNewSitesSend::STATUS_SENT);
            if ($runId && WeeklyNewSitesRecipient::tableReady()) {
                $sendQuery->whereIn(
                    'recipient_id',
                    WeeklyNewSitesRecipient::query()->where('run_id', $runId)->select('id')
                );
            }
            $sends = $sendQuery->count();
        }

        if (WeeklyNewSitesClick::tableReady()) {
            $clickQuery = WeeklyNewSitesClick::query();
            if ($runId && WeeklyNewSitesRecipient::tableReady()) {
                $clickQuery->whereIn(
                    'recipient_id',
                    WeeklyNewSitesRecipient::query()->where('run_id', $runId)->select('id')
                );
            }
            $clicks = (clone $clickQuery)->count();
            $siteClicks = (clone $clickQuery)->where('kind', WeeklyNewSitesClick::KIND_SITE)->count();

            $clickRows = (clone $clickQuery)
                ->where('kind', WeeklyNewSitesClick::KIND_SITE)
                ->whereNotNull('site_id')
                ->get(['user_id', 'site_id', 'clicked_at']);

            foreach ($clickRows as $click) {
                if (! $click->clicked_at instanceof Carbon && ! $click->clicked_at) {
                    continue;
                }
                $from = Carbon::parse($click->clicked_at);
                $until = $from->copy()->addDays($windowDays);
                if ($this->paidOrderAfter($click->user_id, (int) $click->site_id, $from, $until)) {
                    $ordersAfterClick++;
                }
            }
        }

        $latestRun = WeeklyNewSitesRun::tableReady()
            ? WeeklyNewSitesRun::query()->orderByDesc('id')->first()
            : null;

        return [
            'sends' => $sends,
            'clicks' => $clicks,
            'site_clicks' => $siteClicks,
            'orders_after_click' => $ordersAfterClick,
            'attribution_window_days' => $windowDays,
            'latest_run' => $latestRun,
        ];
    }

    private function paidOrderAfter(int $userId, int $siteId, Carbon $from, Carbon $until): bool
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('order_items') || $siteId < 1) {
            return false;
        }

        return OrderItem::query()
            ->where('site_id', $siteId)
            ->whereHas('order', function ($q) use ($userId, $from, $until) {
                $q->where('user_id', $userId)
                    ->whereIn('payment_status', ['paid', 'completed'])
                    ->where(function ($status) {
                        $status->whereNull('status')
                            ->orWhereNotIn('status', ['cancelled', 'canceled']);
                    })
                    ->where(function ($paid) use ($from, $until) {
                        $paid->whereBetween('paid_at', [$from, $until])
                            ->orWhere(function ($created) use ($from, $until) {
                                $created->whereNull('paid_at')->whereBetween('created_at', [$from, $until]);
                            });
                    });
            })
            ->exists();
    }
}
